<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DomainException;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class MailQueueService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTemplateRenderer $renderer = new MailTemplateRenderer(),
    ) {
    }

    /**
     * @param array<string, scalar|null> $publicPlaceholders
     * @param array<string, scalar|null> $secretPlaceholders
     * @throws JsonException
     */
    public function enqueue(
        string $templateKey,
        string $recipientEmail,
        ?string $recipientName,
        array $publicPlaceholders,
        array $secretPlaceholders = [],
        ?string $relationType = null,
        ?int $relationId = null,
        ?string $businessReference = null,
        ?string $deduplicationKey = null,
        int $priority = 100,
        ?string $notAfter = null,
        ?string $availableAt = null,
    ): int {
        $templateKey = trim($templateKey);
        $recipientEmail = mb_strtolower(trim($recipientEmail));
        if ($templateKey === '') {
            throw new DomainException('Der E-Mail-Template-Schlüssel fehlt.');
        }
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Die Empfängeradresse ist ungültig.');
        }
        if ($priority < -1000 || $priority > 1000) {
            throw new DomainException('Die E-Mail-Priorität ist ungültig.');
        }
        if ($relationId !== null && $relationId < 1) {
            throw new DomainException('Die E-Mail-Referenz ist ungültig.');
        }
        if (array_intersect_key($publicPlaceholders, $secretPlaceholders) !== []) {
            throw new DomainException('Ein E-Mail-Platzhalter darf nicht gleichzeitig öffentlich und geheim sein.');
        }

        $template = $this->activeTemplate($templateKey);
        $placeholders = $publicPlaceholders + $secretPlaceholders;
        $rendered = $this->renderer->render(
            $template['subject_template'],
            $template['html_template'],
            $template['text_template'],
            $placeholders,
            $template['allowed_placeholders'],
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO mail_queue '
            . '(mail_template_id, recipient_email, recipient_name, subject, html_body, text_body, '
            . 'placeholder_snapshot, relation_type, relation_id, business_reference, deduplication_key, '
            . 'priority, status, attempts, available_at, not_after, created_at, updated_at) VALUES '
            . '(:template_id, :recipient_email, :recipient_name, :subject, :html_body, :text_body, '
            . ':placeholder_snapshot, :relation_type, :relation_id, :business_reference, :deduplication_key, '
            . ":priority, 'waiting', 0, COALESCE(:available_at, CURRENT_TIMESTAMP), :not_after, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        try {
            $statement->execute([
                'template_id' => $template['id'],
                'recipient_email' => $recipientEmail,
                'recipient_name' => $this->nullable($recipientName),
                'subject' => $rendered->subject,
                'html_body' => $rendered->htmlBody,
                'text_body' => $rendered->textBody,
                'placeholder_snapshot' => json_encode($publicPlaceholders, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'relation_type' => $this->nullable($relationType),
                'relation_id' => $relationId,
                'business_reference' => $this->nullable($businessReference),
                'deduplication_key' => $this->nullable($deduplicationKey),
                'priority' => $priority,
                'not_after' => $this->nullable($notAfter),
                'available_at' => $this->nullable($availableAt),
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000' || $this->nullable($deduplicationKey) === null) {
                throw $exception;
            }
            $existing = $this->pdo->prepare('SELECT id FROM mail_queue WHERE deduplication_key = :key');
            $existing->execute(['key' => trim((string) $deduplicationKey)]);
            $id = $existing->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
            throw $exception;
        }

        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Die E-Mail konnte nicht in die Warteschlange gestellt werden.');
        }

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            'SELECT q.id, q.recipient_email, q.recipient_name, q.subject, q.status, q.attempts, q.available_at, '
            . 'q.not_after, q.sent_at, q.failed_at, q.canceled_at, q.last_error, q.created_at, '
            . 't.template_key, t.version AS template_version '
            . 'FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id '
            . 'ORDER BY q.id DESC LIMIT ' . $limit
        );
        if ($statement === false) {
            throw new RuntimeException('Die E-Mail-Warteschlange konnte nicht geladen werden.');
        }

        return array_values(array_filter($statement->fetchAll(), 'is_array'));
    }

    public function retryNow(int $queueId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'waiting', attempts = 0, available_at = CURRENT_TIMESTAMP, "
            . 'locked_at = NULL, failed_at = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status = 'failed' AND html_body IS NOT NULL AND text_body IS NOT NULL"
        );
        $statement->execute(['id' => $queueId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Diese E-Mail kann nicht erneut versendet werden.');
        }
    }

    public function cancel(int $queueId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP, html_body = NULL, "
            . 'text_body = NULL, locked_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status IN ('waiting', 'failed')"
        );
        $statement->execute(['id' => $queueId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Diese E-Mail kann nicht abgebrochen werden.');
        }
        $this->recordHistory($queueId, 'canceled', null);
    }

    public function recordHistory(int $queueId, string $status, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_delivery_history '
            . '(mail_queue_id, template_key, template_version, recipient_email, subject, placeholder_snapshot, '
            . 'relation_type, relation_id, business_reference, status, attempt_no, error_message, recorded_at) '
            . 'SELECT q.id, t.template_key, t.version, q.recipient_email, q.subject, q.placeholder_snapshot, '
            . 'q.relation_type, q.relation_id, q.business_reference, :status, q.attempts, :error, CURRENT_TIMESTAMP '
            . 'FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id WHERE q.id = :id'
        );
        $statement->execute([
            'status' => $status,
            'error' => $this->nullable($error),
            'id' => $queueId,
        ]);
    }

    /**
     * @return array{id: int, subject_template: string, html_template: string, text_template: string, allowed_placeholders: list<string>}
     * @throws JsonException
     */
    private function activeTemplate(string $templateKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, subject_template, html_template, text_template, allowed_placeholders '
            . 'FROM mail_templates WHERE template_key = :template_key AND active = 1 ORDER BY version DESC LIMIT 1'
        );
        $statement->execute(['template_key' => $templateKey]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Für diese Nachricht ist kein aktives E-Mail-Template vorhanden.');
        }

        $decoded = json_decode((string) $row['allowed_placeholders'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Die Platzhalterdefinition des E-Mail-Templates ist ungültig.');
        }
        $allowed = [];
        foreach ($decoded as $placeholder) {
            if (!is_string($placeholder) || trim($placeholder) === '') {
                throw new RuntimeException('Die Platzhalterdefinition des E-Mail-Templates ist ungültig.');
            }
            $allowed[] = $placeholder;
        }

        return [
            'id' => (int) $row['id'],
            'subject_template' => (string) $row['subject_template'],
            'html_template' => (string) $row['html_template'],
            'text_template' => (string) $row['text_template'],
            'allowed_placeholders' => $allowed,
        ];
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
