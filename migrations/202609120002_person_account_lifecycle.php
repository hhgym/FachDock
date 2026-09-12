<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609120002';
    }

    public function description(): string
    {
        return 'Add lifecycle timestamps for student and parent accounts';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE students '
            . 'ADD COLUMN inactive_since DATETIME NULL AFTER active, '
            . 'ADD COLUMN account_deactivated_at DATETIME NULL AFTER inactive_since, '
            . 'ADD COLUMN account_deactivation_source VARCHAR(24) NULL AFTER account_deactivated_at, '
            . 'ADD COLUMN anonymized_at DATETIME NULL AFTER account_deactivation_source, '
            . 'ADD INDEX idx_students_account_lifecycle (active, inactive_since, account_deactivated_at, anonymized_at)'
        );
        $pdo->exec(
            'UPDATE students SET anonymized_at = updated_at, inactive_since = COALESCE(updated_at, created_at) '
            . "WHERE first_name = 'Anonymisiert' AND active = 0"
        );
        $pdo->exec(
            'UPDATE students SET inactive_since = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP) '
            . 'WHERE active = 0 AND inactive_since IS NULL AND anonymized_at IS NULL'
        );

        $pdo->exec(
            'ALTER TABLE parent_contacts '
            . 'ADD COLUMN lifecycle_started_at DATETIME NULL AFTER active, '
            . 'ADD COLUMN deactivated_at DATETIME NULL AFTER lifecycle_started_at, '
            . 'ADD COLUMN deactivation_source VARCHAR(24) NULL AFTER deactivated_at, '
            . 'ADD COLUMN anonymized_at DATETIME NULL AFTER deactivation_source, '
            . 'ADD INDEX idx_parent_account_lifecycle (active, lifecycle_started_at, deactivated_at, anonymized_at)'
        );
        $pdo->exec(
            'UPDATE parent_contacts SET anonymized_at = updated_at, deactivated_at = COALESCE(updated_at, created_at), '
            . "deactivation_source = 'lifecycle' WHERE status = 'anonymized'"
        );
        $pdo->exec(
            'UPDATE parent_contacts pc SET lifecycle_started_at = COALESCE(NULLIF(GREATEST('
            . 'COALESCE((SELECT MAX(s.inactive_since) FROM parent_student_link_slots psls '
            . "INNER JOIN students s ON s.id = psls.student_id WHERE psls.parent_contact_id = pc.id), '1000-01-01 00:00:00'), "
            . "COALESCE((SELECT MAX(psl.ended_at) FROM parent_student_links psl WHERE psl.parent_contact_id = pc.id), '1000-01-01 00:00:00')"
            . "), '1000-01-01 00:00:00'), pc.updated_at, pc.created_at, CURRENT_TIMESTAMP) "
            . "WHERE pc.status <> 'anonymized' AND NOT EXISTS ("
            . 'SELECT 1 FROM parent_student_link_slots psls '
            . 'INNER JOIN students s ON s.id = psls.student_id '
            . 'WHERE psls.parent_contact_id = pc.id AND s.active = 1 AND s.anonymized_at IS NULL)'
        );

        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) '
            . 'VALUES (:key, :value, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE setting_value = setting_value'
        );
        foreach ([
            'privacy.student_deactivation_days' => '30',
            'privacy.student_anonymization_days' => '365',
            'privacy.parent_deactivation_days' => '1095',
            'privacy.parent_anonymization_days' => '1095',
        ] as $key => $value) {
            $statement->execute(['key' => $key, 'value' => $value]);
        }
    }
};
