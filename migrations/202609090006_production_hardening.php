<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090006';
    }

    public function description(): string
    {
        return 'Add privacy retention and anonymization run tracking';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE privacy_anonymization_runs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'cutoff_date DATE NOT NULL,'
            . 'status VARCHAR(24) NOT NULL,'
            . 'summary_json LONGTEXT NOT NULL,'
            . 'staff_user_id BIGINT UNSIGNED NOT NULL,'
            . 'started_at DATETIME NOT NULL,'
            . 'finished_at DATETIME NULL,'
            . 'INDEX idx_privacy_runs_started (started_at),'
            . 'CONSTRAINT fk_privacy_runs_staff FOREIGN KEY (staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) '
            . 'VALUES (:key, :value, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE setting_value = setting_value'
        );
        $statement->execute(['key' => 'privacy.retention_years', 'value' => '3']);
        $statement->execute(['key' => 'privacy.mail_retention_days', 'value' => '365']);
    }
};
