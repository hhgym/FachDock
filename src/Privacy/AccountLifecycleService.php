<?php

declare(strict_types=1);

namespace FachDock\Privacy;

use DateTimeImmutable;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class AccountLifecycleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{student_deactivation_days:int,student_anonymization_days:int,parent_deactivation_days:int,parent_anonymization_days:int} */
    public function settings(): array
    {
        return [
            'student_deactivation_days' => $this->settingInt('privacy.student_deactivation_days', 30, 1, 3650),
            'student_anonymization_days' => $this->settingInt('privacy.student_anonymization_days', 365, 1, 3650),
            'parent_deactivation_days' => $this->settingInt('privacy.parent_deactivation_days', 1095, 1, 7300),
            'parent_anonymization_days' => $this->settingInt('privacy.parent_anonymization_days', 1095, 1, 7300),
        ];
    }

    public function saveSettings(
        int $studentDeactivationDays,
        int $studentAnonymizationDays,
        int $parentDeactivationDays,
        int $parentAnonymizationDays,
    ): void {
        foreach ([
            'Schüler-Deaktivierung' => [$studentDeactivationDays, 1, 3650],
            'Schüler-Anonymisierung' => [$studentAnonymizationDays, 1, 3650],
            'Eltern-Deaktivierung' => [$parentDeactivationDays, 1, 7300],
            'Eltern-Anonymisierung' => [$parentAnonymizationDays, 1, 7300],
        ] as $label => [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new DomainException($label . ' liegt außerhalb des zulässigen Bereichs.');
            }
        }
        if ($studentAnonymizationDays < $studentDeactivationDays) {
            throw new DomainException('Die Schüler-Anonymisierung darf nicht vor der Deaktivierung liegen.');
        }
        if ($parentAnonymizationDays < $parentDeactivationDays) {
            throw new DomainException('Die Eltern-Anonymisierung darf nicht vor der Deaktivierung liegen.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        foreach ([
            'privacy.student_deactivation_days' => $studentDeactivationDays,
            'privacy.student_anonymization_days' => $studentAnonymizationDays,
            'privacy.parent_deactivation_days' => $parentDeactivationDays,
            'privacy.parent_anonymization_days' => $parentAnonymizationDays,
        ] as $key => $value) {
            $statement->execute(['key' => $key, 'value' => (string) $value]);
        }
    }

    /** @return array{students_to_deactivate:int,students_to_anonymize:int,parents_to_deactivate:int,parents_to_anonymize:int} */
    public function preview(?DateTimeImmutable $today = null): array
    {
        $this->synchronizeLifecycleStarts();
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();

        return [
            'students_to_deactivate' => $this->countStudentsDue($today, $settings['student_deactivation_days'], false),
            'students_to_anonymize' => $this->countStudentsDue($today, $settings['student_anonymization_days'], true),
            'parents_to_deactivate' => $this->countParentsDue($today, $settings['parent_deactivation_days'], false),
            'parents_to_anonymize' => $this->countParentsDue($today, $settings['parent_anonymization_days'], true),
        ];
    }

    /** @return array{students_deactivated:int,students_anonymized:int,parents_deactivated:int,parents_anonymized:int} */
    public function process(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $settings = $this->settings();
        $this->synchronizeLifecycleStarts();

        $studentDeactivationIds = $this->studentIdsDue($today, $settings['student_deactivation_days'], false);
        $studentAnonymizationIds = $this->studentIdsDue($today, $settings['student_anonymization_days'], true);
        $parentDeactivationIds = $this->parentIdsDue($today, $settings['parent_deactivation_days'], false);
        $parentAnonymizationIds = $this->parentIdsDue($today, $settings['parent_anonymization_days'], true);

        $this->pdo->beginTransaction();
        try {
            foreach ($studentDeactivationIds as $studentId) {
                $this->deactivateStudentInternal($studentId, 'lifecycle');
            }
            foreach ($studentAnonymizationIds as $studentId) {
                $this->anonymizeStudentInternal($studentId);
            }
            foreach ($parentDeactivationIds as $parentId) {
                $this->deactivateParentInternal($parentId, 'lifecycle');
            }
            foreach ($parentAnonymizationIds as $parentId) {
                $this->anonymizeParentInternal($parentId);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'students_deactivated' => count($studentDeactivationIds),
            'students_anonymized' => count($studentAnonymizationIds),
            'parents_deactivated' => count($parentDeactivationIds),
            'parents_anonymized' => count($parentAnonymizationIds),
        ];
    }

    public function deactivateStudent(int $studentId): void
    {
        $this->requireStudent($studentId);
        $this->deactivateStudentInternal($studentId, 'manual');
    }

    public function reactivateStudent(int $studentId): void
    {
        $student = $this->requireStudent($studentId);
        if ($student['anonymized_at'] !== null) {
            throw new DomainException('Ein anonymisierter Schüleraccount kann nicht reaktiviert werden.');
        }
        if ($student['account_deactivated_at'] === null) {
            throw new DomainException('Der Schüleraccount ist bereits aktiv.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE students SET account_deactivated_at = NULL, account_deactivation_source = NULL, '
            . 'inactive_since = CASE WHEN active = 0 THEN CURRENT_TIMESTAMP ELSE NULL END, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute(['id' => $studentId]);
        $this->pdo->prepare(
            "UPDATE oidc_identities SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE student_id = :id AND identity_type = 'student'"
        )->execute(['id' => $studentId]);
    }

    public function anonymizeStudent(int $studentId): void
    {
        $this->requireStudent($studentId);
        $this->pdo->beginTransaction();
        try {
            $this->anonymizeStudentInternal($studentId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deactivateParent(int $parentId): void
    {
        $this->requireParent($parentId);
        $this->deactivateParentInternal($parentId, 'manual');
    }

    public function reactivateParent(int $parentId): void
    {
        $parent = $this->requireParent($parentId);
        if ($parent['anonymized_at'] !== null || (string) $parent['status'] === 'anonymized') {
            throw new DomainException('Ein anonymisiertes Elternkonto kann nicht reaktiviert werden.');
        }
        if ((int) $parent['active'] === 1 && $parent['deactivated_at'] === null) {
            throw new DomainException('Das Elternkonto ist bereits aktiv.');
        }
        $hasActiveChild = $this->parentHasActiveChild($parentId);
        $statement = $this->pdo->prepare(
            'UPDATE parent_contacts SET active = 1, deactivated_at = NULL, deactivation_source = NULL, '
            . 'lifecycle_started_at = :lifecycle_started_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $parentId,
            'lifecycle_started_at' => $hasActiveChild ? null : date('Y-m-d H:i:s'),
        ]);
    }

    public function anonymizeParent(int $parentId): void
    {
        $this->requireParent($parentId);
        $this->pdo->beginTransaction();
        try {
            $this->anonymizeParentInternal($parentId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function synchronizeLifecycleStarts(): void
    {
        $this->pdo->exec(
            'UPDATE students SET inactive_since = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP) '
            . 'WHERE active = 0 AND anonymized_at IS NULL AND inactive_since IS NULL'
        );
        $this->pdo->exec(
            'UPDATE students SET inactive_since = NULL '
            . 'WHERE active = 1 AND anonymized_at IS NULL AND inactive_since IS NOT NULL'
        );

        $reactivated = $this->pdo->query(
            "SELECT id FROM students WHERE active = 1 AND anonymized_at IS NULL "
            . "AND account_deactivated_at IS NOT NULL AND account_deactivation_source = 'lifecycle'"
        );
        if ($reactivated === false) {
            throw new RuntimeException('Automatisch zu reaktivierende Schülerkonten konnten nicht geladen werden.');
        }
        foreach ($reactivated->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $studentId = (int) $id;
            $this->pdo->prepare(
                'UPDATE students SET account_deactivated_at = NULL, account_deactivation_source = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $studentId]);
            $this->pdo->prepare(
                "UPDATE oidc_identities SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE student_id = :id AND identity_type = 'student'"
            )->execute(['id' => $studentId]);
        }

        $parents = $this->pdo->query(
            "SELECT id, active, deactivation_source, lifecycle_started_at FROM parent_contacts WHERE status <> 'anonymized' AND anonymized_at IS NULL"
        );
        if ($parents === false) {
            throw new RuntimeException('Elternkonten konnten für den Lifecycle nicht geladen werden.');
        }
        foreach ($parents->fetchAll(PDO::FETCH_ASSOC) as $parent) {
            if (!is_array($parent)) {
                continue;
            }
            $parentId = (int) $parent['id'];
            $hasActiveChild = $this->parentHasActiveChild($parentId);
            if ($hasActiveChild) {
                $this->pdo->prepare(
                    'UPDATE parent_contacts SET lifecycle_started_at = NULL, '
                    . "active = CASE WHEN deactivation_source = 'lifecycle' THEN 1 ELSE active END, "
                    . "deactivated_at = CASE WHEN deactivation_source = 'lifecycle' THEN NULL ELSE deactivated_at END, "
                    . "deactivation_source = CASE WHEN deactivation_source = 'lifecycle' THEN NULL ELSE deactivation_source END, "
                    . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute(['id' => $parentId]);
                continue;
            }
            if ($parent['lifecycle_started_at'] === null) {
                $this->pdo->prepare(
                    'UPDATE parent_contacts SET lifecycle_started_at = :started_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute([
                    'id' => $parentId,
                    'started_at' => $this->parentLifecycleStartAt($parentId),
                ]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function requireStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, active, inactive_since, account_deactivated_at, account_deactivation_source, anonymized_at '
            . 'FROM students WHERE id = :id'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Der Schülerdatensatz existiert nicht.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function requireParent(int $parentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, active, status, lifecycle_started_at, deactivated_at, deactivation_source, anonymized_at '
            . 'FROM parent_contacts WHERE id = :id'
        );
        $statement->execute(['id' => $parentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Das Elternkonto existiert nicht.');
        }

        return $row;
    }

    private function deactivateStudentInternal(int $studentId, string $source): void
    {
        $student = $this->requireStudent($studentId);
        if ($student['anonymized_at'] !== null) {
            throw new DomainException('Ein anonymisierter Schüleraccount kann nicht deaktiviert werden.');
        }
        if ($student['account_deactivated_at'] !== null) {
            if ($source === 'lifecycle') {
                return;
            }
            throw new DomainException('Der Schüleraccount ist bereits deaktiviert.');
        }
        $this->pdo->prepare(
            'UPDATE students SET account_deactivated_at = CURRENT_TIMESTAMP, account_deactivation_source = :source, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['source' => $source, 'id' => $studentId]);
        $identityIds = $this->identityIdsForStudent($studentId);
        foreach ($identityIds as $identityId) {
            $this->pdo->prepare('UPDATE oidc_identities SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => $identityId]);
            $this->pdo->prepare(
                'UPDATE oidc_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE identity_id = :id'
            )->execute(['id' => $identityId]);
        }
    }

    private function deactivateParentInternal(int $parentId, string $source): void
    {
        $parent = $this->requireParent($parentId);
        if ($parent['anonymized_at'] !== null || (string) $parent['status'] === 'anonymized') {
            throw new DomainException('Ein anonymisiertes Elternkonto kann nicht deaktiviert werden.');
        }
        if ((int) $parent['active'] === 0 && $parent['deactivated_at'] !== null) {
            if ($source === 'lifecycle') {
                return;
            }
            throw new DomainException('Das Elternkonto ist bereits deaktiviert.');
        }
        $this->pdo->prepare(
            'UPDATE parent_contacts SET active = 0, deactivated_at = CURRENT_TIMESTAMP, deactivation_source = :source, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['source' => $source, 'id' => $parentId]);
        $this->pdo->prepare(
            'UPDATE parent_sessions SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) '
            . 'WHERE parent_contact_id = :id'
        )->execute(['id' => $parentId]);
        $this->pdo->prepare('DELETE FROM parent_magic_links WHERE parent_contact_id = :id')->execute(['id' => $parentId]);
    }

    private function anonymizeStudentInternal(int $studentId): void
    {
        $student = $this->requireStudent($studentId);
        if ($student['anonymized_at'] !== null) {
            return;
        }
        $this->deactivateStudentInternal($studentId, 'lifecycle');
        $this->pdo->prepare('DELETE FROM parent_student_link_slots WHERE student_id = :id')->execute(['id' => $studentId]);
        $this->pdo->prepare(
            'UPDATE parent_student_links SET ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP), '
            . "end_reason = COALESCE(end_reason, 'Datenschutz-Anonymisierung') WHERE student_id = :id AND ended_at IS NULL"
        )->execute(['id' => $studentId]);
        $this->pdo->prepare(
            "UPDATE locker_incidents SET reporter_email = NULL, reporter_name = 'Anonymisiert', description = '[anonymisiert]', "
            . "resolution_note = CASE WHEN resolution_note IS NULL THEN NULL ELSE '[anonymisiert]' END WHERE student_id = :id"
        )->execute(['id' => $studentId]);
        $this->pdo->prepare(
            "UPDATE bookings SET student_snapshot = '{\"anonymized\":true}' WHERE student_id = :id"
        )->execute(['id' => $studentId]);
        $identities = $this->identityIdsForStudent($studentId);
        foreach ($identities as $identityId) {
            $this->pdo->prepare(
                "UPDATE oidc_identities SET subject = :subject, uuid = NULL, account_name = NULL, display_name = NULL, "
                . "email = NULL, identity_type = 'pending', active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([
                'subject' => 'anonymized-student-' . $studentId . '-' . $identityId,
                'id' => $identityId,
            ]);
        }
        $this->pdo->prepare(
            "UPDATE students SET matrikelnummer = :matrikel, first_name = 'Anonymisiert', last_name = :last_name, "
            . "class_name = '-', grade = 0, email = NULL, access_code_hash = NULL, access_code_generated_at = NULL, "
            . "active = 0, account_deactivated_at = COALESCE(account_deactivated_at, CURRENT_TIMESTAMP), "
            . "account_deactivation_source = COALESCE(account_deactivation_source, 'lifecycle'), anonymized_at = CURRENT_TIMESTAMP, "
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([
            'id' => $studentId,
            'matrikel' => 'ANON-' . $studentId . '-' . substr(hash('sha256', 'student:' . $studentId), 0, 12),
            'last_name' => '#' . $studentId,
        ]);
    }

    private function anonymizeParentInternal(int $parentId): void
    {
        $parent = $this->requireParent($parentId);
        if ($parent['anonymized_at'] !== null || (string) $parent['status'] === 'anonymized') {
            return;
        }
        $this->deactivateParentInternal($parentId, 'lifecycle');
        $this->pdo->prepare('DELETE FROM parent_student_link_slots WHERE parent_contact_id = :id')->execute(['id' => $parentId]);
        $this->pdo->prepare(
            'UPDATE parent_student_links SET ended_at = COALESCE(ended_at, CURRENT_TIMESTAMP), '
            . "end_reason = COALESCE(end_reason, 'Datenschutz-Anonymisierung') WHERE parent_contact_id = :id AND ended_at IS NULL"
        )->execute(['id' => $parentId]);
        $this->pdo->prepare(
            "UPDATE parent_contacts SET email = :email, first_name = NULL, last_name = NULL, status = 'anonymized', "
            . 'verified_at = NULL, stripe_customer_id = NULL, active = 0, '
            . 'deactivated_at = COALESCE(deactivated_at, CURRENT_TIMESTAMP), '
            . "deactivation_source = COALESCE(deactivation_source, 'lifecycle'), anonymized_at = CURRENT_TIMESTAMP, "
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $parentId, 'email' => 'anon-parent-' . $parentId . '@invalid.local']);
    }

    /** @return list<int> */
    private function identityIdsForStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM oidc_identities WHERE student_id = :id ORDER BY id');
        $statement->execute(['id' => $studentId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function parentHasActiveChild(int $parentId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM parent_student_link_slots psls INNER JOIN students s ON s.id = psls.student_id '
            . 'WHERE psls.parent_contact_id = :id AND s.active = 1 AND s.anonymized_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $parentId]);

        return $statement->fetchColumn() !== false;
    }

    private function parentLifecycleStartAt(int $parentId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(GREATEST("
            . "COALESCE((SELECT MAX(s.inactive_since) FROM parent_student_link_slots psls "
            . "INNER JOIN students s ON s.id = psls.student_id WHERE psls.parent_contact_id = :slot_parent), '1000-01-01 00:00:00'), "
            . "COALESCE((SELECT MAX(psl.ended_at) FROM parent_student_links psl WHERE psl.parent_contact_id = :history_parent), '1000-01-01 00:00:00')"
            . "), '1000-01-01 00:00:00'), CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'slot_parent' => $parentId,
            'history_parent' => $parentId,
        ]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            return date('Y-m-d H:i:s');
        }

        return $value;
    }

    /** @return list<int> */
    private function studentIdsDue(DateTimeImmutable $today, int $days, bool $anonymization): array
    {
        $cutoff = $today->modify('-' . $days . ' days')->format('Y-m-d 23:59:59');
        $sql = 'SELECT id FROM students WHERE active = 0 AND anonymized_at IS NULL AND inactive_since IS NOT NULL '
            . 'AND inactive_since <= :cutoff ';
        $sql .= $anonymization ? '' : 'AND account_deactivated_at IS NULL ';
        $sql .= 'ORDER BY id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['cutoff' => $cutoff]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function countStudentsDue(DateTimeImmutable $today, int $days, bool $anonymization): int
    {
        return count($this->studentIdsDue($today, $days, $anonymization));
    }

    /** @return list<int> */
    private function parentIdsDue(DateTimeImmutable $today, int $days, bool $anonymization): array
    {
        $cutoff = $today->modify('-' . $days . ' days')->format('Y-m-d 23:59:59');
        $sql = "SELECT id FROM parent_contacts WHERE status <> 'anonymized' AND anonymized_at IS NULL "
            . 'AND lifecycle_started_at IS NOT NULL AND lifecycle_started_at <= :cutoff ';
        $sql .= $anonymization ? '' : 'AND active = 1 AND deactivated_at IS NULL ';
        $sql .= 'ORDER BY id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['cutoff' => $cutoff]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function countParentsDue(DateTimeImmutable $today, int $days, bool $anonymization): int
    {
        return count($this->parentIdsDue($today, $days, $anonymization));
    }

    private function settingInt(string $key, int $default, int $min, int $max): int
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        $parsed = $value !== false && is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $parsed));
    }
}
