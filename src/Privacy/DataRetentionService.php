<?php

declare(strict_types=1);

namespace FachDock\Privacy;

use DateTimeImmutable;
use DomainException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class DataRetentionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{retention_years:int,mail_retention_days:int} */
    public function settings(): array
    {
        return [
            'retention_years' => $this->settingInt('privacy.retention_years', 3, 1, 30),
            'mail_retention_days' => $this->settingInt('privacy.mail_retention_days', 365, 30, 3650),
        ];
    }

    public function saveSettings(int $retentionYears, int $mailRetentionDays): void
    {
        if ($retentionYears < 1 || $retentionYears > 30) {
            throw new DomainException('Die Aufbewahrungsfrist muss zwischen 1 und 30 Jahren liegen.');
        }
        if ($mailRetentionDays < 30 || $mailRetentionDays > 3650) {
            throw new DomainException('Die E-Mail-Aufbewahrung muss zwischen 30 und 3650 Tagen liegen.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        foreach ([
            'privacy.retention_years' => $retentionYears,
            'privacy.mail_retention_days' => $mailRetentionDays,
        ] as $key => $value) {
            $statement->execute(['key' => $key, 'value' => (string) $value]);
        }
    }

    /** @return array{cutoff_date:string,mail_cutoff_date:string,students:int,parents:int,mails:int,audit_entries:int} */
    public function preview(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        $cutoff = $today->modify('-' . $settings['retention_years'] . ' years')->format('Y-m-d');
        $mailCutoff = $today->modify('-' . $settings['mail_retention_days'] . ' days')->format('Y-m-d H:i:s');

        return [
            'cutoff_date' => $cutoff,
            'mail_cutoff_date' => $mailCutoff,
            'students' => $this->candidateStudentCount($cutoff),
            'parents' => $this->candidateParentCount($cutoff),
            'mails' => $this->countBefore('mail_queue', 'created_at', $mailCutoff),
            'audit_entries' => $this->countBefore('audit_log', 'created_at', $cutoff . ' 00:00:00'),
        ];
    }

    /**
     * @return array{students:int,parents:int,mails:int,mail_history:int,audit_entries:int,incidents:int}
     * @throws JsonException
     */
    public function anonymize(int $staffUserId, ?DateTimeImmutable $today = null): array
    {
        if ($staffUserId < 1) {
            throw new DomainException('Administrator ist ungültig.');
        }
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        $cutoff = $today->modify('-' . $settings['retention_years'] . ' years')->format('Y-m-d');
        $mailCutoff = $today->modify('-' . $settings['mail_retention_days'] . ' days')->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare(
                'INSERT INTO privacy_anonymization_runs '
                . "(cutoff_date, status, summary_json, staff_user_id, started_at) VALUES (:cutoff, 'running', '{}', :staff, CURRENT_TIMESTAMP)"
            );
            $run->execute(['cutoff' => $cutoff, 'staff' => $staffUserId]);
            $runId = (int) $this->pdo->lastInsertId();
            if ($runId < 1) {
                throw new RuntimeException('Der Datenschutzlauf konnte nicht protokolliert werden.');
            }

            $studentIds = $this->candidateStudentIds($cutoff);
            $incidents = 0;
            foreach ($studentIds as $studentId) {
                $this->pdo->prepare('DELETE FROM parent_student_link_slots WHERE student_id = :id')->execute(['id' => $studentId]);
                $this->pdo->prepare(
                    "UPDATE parent_student_links SET ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP), "
                    . "end_reason = COALESCE(end_reason, 'Datenschutz-Anonymisierung') WHERE student_id = :id AND ended_at IS NULL"
                )->execute(['id' => $studentId]);
                $incidentUpdate = $this->pdo->prepare(
                    "UPDATE locker_incidents SET reporter_email = NULL, reporter_name = 'Anonymisiert', "
                    . "description = '[anonymisiert]', resolution_note = CASE WHEN resolution_note IS NULL THEN NULL ELSE '[anonymisiert]' END "
                    . 'WHERE student_id = :id'
                );
                $incidentUpdate->execute(['id' => $studentId]);
                $incidents += $incidentUpdate->rowCount();
                $this->pdo->prepare(
                    "UPDATE bookings SET student_snapshot = '{\"anonymized\":true}' WHERE student_id = :id"
                )->execute(['id' => $studentId]);
                $this->pdo->prepare(
                    "UPDATE students SET matrikelnummer = :matrikel, first_name = 'Anonymisiert', last_name = :last_name, "
                    . "class_name = '-', email = NULL, access_code_hash = NULL, access_code_generated_at = NULL, "
                    . 'active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute([
                    'id' => $studentId,
                    'matrikel' => 'ANON-' . $studentId . '-' . substr(hash('sha256', 'student:' . $studentId), 0, 12),
                    'last_name' => '#' . $studentId,
                ]);
            }

            $parentIds = $this->candidateParentIds($cutoff);
            foreach ($parentIds as $parentId) {
                $this->pdo->prepare(
                    'UPDATE parent_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE parent_contact_id = :id'
                )->execute(['id' => $parentId]);
                $this->pdo->prepare('DELETE FROM parent_magic_links WHERE parent_contact_id = :id')->execute(['id' => $parentId]);
                $this->pdo->prepare(
                    "UPDATE parent_contacts SET email = :email, first_name = NULL, last_name = NULL, status = 'anonymized', "
                    . 'verified_at = NULL, stripe_customer_id = NULL, active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute(['id' => $parentId, 'email' => 'anon-parent-' . $parentId . '@invalid.local']);
            }

            $mail = $this->pdo->prepare(
                "UPDATE mail_queue SET recipient_email = 'redacted@invalid.local', recipient_name = NULL, "
                . "subject = '[anonymisiert]', html_body = NULL, text_body = NULL, placeholder_snapshot = '{}', "
                . 'last_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE created_at < :cutoff'
            );
            $mail->execute(['cutoff' => $mailCutoff]);
            $mailCount = $mail->rowCount();
            $history = $this->pdo->prepare(
                "UPDATE mail_delivery_history SET recipient_email = 'redacted@invalid.local', subject = '[anonymisiert]', "
                . "placeholder_snapshot = '{}', error_message = NULL WHERE recorded_at < :cutoff"
            );
            $history->execute(['cutoff' => $mailCutoff]);
            $historyCount = $history->rowCount();
            $audit = $this->pdo->prepare("UPDATE audit_log SET metadata = NULL WHERE created_at < :cutoff AND metadata IS NOT NULL");
            $audit->execute(['cutoff' => $cutoff . ' 00:00:00']);
            $auditCount = $audit->rowCount();

            $summary = [
                'students' => count($studentIds),
                'parents' => count($parentIds),
                'mails' => $mailCount,
                'mail_history' => $historyCount,
                'audit_entries' => $auditCount,
                'incidents' => $incidents,
            ];
            $this->pdo->prepare(
                "UPDATE privacy_anonymization_runs SET status = 'completed', summary_json = :summary, finished_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([
                'id' => $runId,
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->pdo->commit();

            return $summary;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            'SELECT r.*, su.display_name AS staff_name FROM privacy_anonymization_runs r '
            . 'INNER JOIN staff_users su ON su.id = r.staff_user_id ORDER BY r.id DESC LIMIT ' . $limit
        );
        if ($statement === false) {
            throw new RuntimeException('Die Datenschutzläufe konnten nicht geladen werden.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    private function candidateStudentIds(string $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id FROM students s WHERE s.active = 0 '
            . "AND s.first_name <> 'Anonymisiert' "
            . 'AND ((NOT EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id) AND DATE(s.created_at) < :cutoff) '
            . 'OR (EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id) '
            . 'AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.valid_until >= :cutoff2))) '
            . 'ORDER BY s.id FOR UPDATE'
        );
        $statement->execute(['cutoff' => $cutoff, 'cutoff2' => $cutoff]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function candidateStudentCount(string $cutoff): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM students s WHERE s.active = 0 '
            . "AND s.first_name <> 'Anonymisiert' "
            . 'AND ((NOT EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id) AND DATE(s.created_at) < :cutoff) '
            . 'OR (EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id) '
            . 'AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.valid_until >= :cutoff2)))'
        );
        $statement->execute(['cutoff' => $cutoff, 'cutoff2' => $cutoff]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<int> */
    private function candidateParentIds(string $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pc.id FROM parent_contacts pc WHERE pc.active = 1 AND DATE(pc.updated_at) < :cutoff '
            . 'AND NOT EXISTS (SELECT 1 FROM parent_student_link_slots psls WHERE psls.parent_contact_id = pc.id) '
            . "AND pc.status <> 'anonymized' ORDER BY pc.id FOR UPDATE"
        );
        $statement->execute(['cutoff' => $cutoff]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function candidateParentCount(string $cutoff): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM parent_contacts pc WHERE pc.active = 1 AND DATE(pc.updated_at) < :cutoff '
            . 'AND NOT EXISTS (SELECT 1 FROM parent_student_link_slots psls WHERE psls.parent_contact_id = pc.id) '
            . "AND pc.status <> 'anonymized'"
        );
        $statement->execute(['cutoff' => $cutoff]);

        return (int) $statement->fetchColumn();
    }

    private function settingInt(string $key, int $default, int $min, int $max): int
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        $parsed = $value !== false && is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $parsed));
    }

    private function countBefore(string $table, string $column, string $cutoff): int
    {
        $allowed = ['mail_queue.created_at', 'audit_log.created_at'];
        if (!in_array($table . '.' . $column, $allowed, true)) {
            throw new RuntimeException('Ungültige Datenschutzabfrage.');
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return (int) $statement->fetchColumn();
    }
}
