<?php

declare(strict_types=1);

namespace FachDock\Mail;

use PDO;
use Throwable;

final class MailWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailSender $sender,
        private readonly MailQueueService $queue,
        private readonly MailRetrySchedule $retrySchedule = new MailRetrySchedule(),
        private readonly int $maxPerHour = 50,
        private readonly int $processingTimeoutMinutes = 15,
    ) {
    }

    /** @return array{processed: int, sent: int, deferred: int, failed: int, expired: int} */
    public function run(int $batchSize = 50): array
    {
        $batchSize = max(1, min(500, $batchSize));
        $this->recoverStaleProcessing();
        $capacity = max(0, max(1, $this->maxPerHour) - $this->sentLastHour());
        $limit = min($batchSize, $capacity);
        $result = ['processed' => 0, 'sent' => 0, 'deferred' => 0, 'failed' => 0, 'expired' => 0];

        for ($index = 0; $index < $limit; $index++) {
            $message = $this->claimNext();
            if ($message === null) {
                break;
            }
            $result['processed']++;

            if ($this->shouldSuppress($message)) {
                $this->finishSuppressed($message['id']);
                continue;
            }

            if ($message['expired']) {
                $this->finishExpired($message['id']);
                $result['expired']++;
                continue;
            }

            try {
                $this->sender->send(new MailMessage(
                    $message['recipient_email'],
                    $message['recipient_name'],
                    $message['subject'],
                    $message['html_body'],
                    $message['text_body'],
                ));
                $this->finishSent($message['id']);
                $result['sent']++;
            } catch (Throwable $exception) {
                if ($this->finishFailure($message['id'], $exception->getMessage())) {
                    $result['deferred']++;
                } else {
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   id: int, recipient_email: string, recipient_name: string|null, subject: string,
     *   html_body: string, text_body: string, expired: bool, template_key: string,
     *   relation_type: string|null, relation_id: int|null
     * }|null
     */
    private function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query(
                'SELECT q.id, q.recipient_email, q.recipient_name, q.subject, q.html_body, q.text_body, '
                . 'q.relation_type, q.relation_id, t.template_key, '
                . '(q.not_after IS NOT NULL AND q.not_after <= CURRENT_TIMESTAMP) AS expired '
                . 'FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id '
                . "WHERE q.status = 'waiting' AND q.available_at <= CURRENT_TIMESTAMP "
                . 'ORDER BY q.priority ASC, q.id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $row = $statement === false ? false : $statement->fetch();
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $id = (int) $row['id'];
            $this->pdo->prepare(
                "UPDATE mail_queue SET status = 'processing', locked_at = CURRENT_TIMESTAMP, "
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $id]);
            $this->pdo->commit();

            return [
                'id' => $id,
                'recipient_email' => (string) $row['recipient_email'],
                'recipient_name' => $row['recipient_name'] === null ? null : (string) $row['recipient_name'],
                'subject' => (string) $row['subject'],
                'html_body' => (string) ($row['html_body'] ?? ''),
                'text_body' => (string) ($row['text_body'] ?? ''),
                'expired' => (int) $row['expired'] === 1,
                'template_key' => (string) $row['template_key'],
                'relation_type' => $row['relation_type'] === null ? null : (string) $row['relation_type'],
                'relation_id' => $row['relation_id'] === null ? null : (int) $row['relation_id'],
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array{template_key: string, relation_type: string|null, relation_id: int|null} $message */
    private function shouldSuppress(array $message): bool
    {
        if ($message['template_key'] !== 'payment_due_reminder'
            || $message['relation_type'] !== 'booking'
            || $message['relation_id'] === null) {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT status FROM bookings WHERE id = :id');
        $statement->execute(['id' => $message['relation_id']]);

        return $statement->fetchColumn() !== 'payment_due';
    }

    private function finishSuppressed(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP, locked_at = NULL, "
            . 'html_body = NULL, text_body = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $id]);
        $this->queue->recordHistory($id, 'canceled', 'Zahlungserinnerung war nicht mehr erforderlich.');
    }

    private function finishSent(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'sent', attempts = attempts + 1, sent_at = CURRENT_TIMESTAMP, "
            . 'locked_at = NULL, html_body = NULL, text_body = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        )->execute(['id' => $id]);
        $this->queue->recordHistory($id, 'sent', null);
    }

    private function finishExpired(int $id): void
    {
        $error = 'Die Nachricht ist vor dem Versand abgelaufen.';
        $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'failed', failed_at = CURRENT_TIMESTAMP, locked_at = NULL, "
            . 'html_body = NULL, text_body = NULL, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $id, 'error' => $error]);
        $this->queue->recordHistory($id, 'expired', $error);
    }

    private function finishFailure(int $id, string $error): bool
    {
        $attempts = $this->attempts($id) + 1;
        $delay = $this->retrySchedule->delayAfterFailure($attempts);
        $error = mb_substr(trim($error), 0, 1000);

        if ($delay === null) {
            $this->pdo->prepare(
                "UPDATE mail_queue SET status = 'failed', attempts = :attempts, failed_at = CURRENT_TIMESTAMP, "
                . 'locked_at = NULL, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
            $this->queue->recordHistory($id, 'failed', $error);
            return false;
        }

        $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'waiting', attempts = :attempts, "
            . 'available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $delay . ' MINUTE), '
            . 'locked_at = NULL, last_error = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
        $this->queue->recordHistory($id, 'retry_scheduled', $error);
        return true;
    }

    private function attempts(int $id): int
    {
        $statement = $this->pdo->prepare('SELECT attempts FROM mail_queue WHERE id = :id');
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function sentLastHour(): int
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM mail_delivery_history WHERE status = 'sent' "
            . 'AND recorded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
        );
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function recoverStaleProcessing(): void
    {
        $timeout = max(1, min(1440, $this->processingTimeoutMinutes));
        $this->pdo->exec(
            "UPDATE mail_queue SET status = 'waiting', locked_at = NULL, updated_at = CURRENT_TIMESTAMP "
            . "WHERE status = 'processing' AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$timeout} MINUTE)"
        );
    }
}
