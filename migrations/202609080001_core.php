<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080001';
    }

    public function description(): string
    {
        return 'Create core system tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_users ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'username VARCHAR(100) NOT NULL UNIQUE,'
            . 'display_name VARCHAR(255) NOT NULL,'
            . 'email VARCHAR(255) NOT NULL UNIQUE,'
            . 'password_hash VARCHAR(255) NOT NULL,'
            . 'role VARCHAR(32) NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'last_login_at DATETIME NULL,'
            . 'last_failed_login_at DATETIME NULL,'
            . 'failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_staff_users_role_active (role, active)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_settings ('
            . 'setting_key VARCHAR(128) NOT NULL PRIMARY KEY,'
            . 'setting_value LONGTEXT NOT NULL,'
            . 'updated_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_log ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'actor_type VARCHAR(32) NOT NULL,'
            . 'staff_user_id BIGINT UNSIGNED NULL,'
            . 'action VARCHAR(128) NOT NULL,'
            . 'entity_type VARCHAR(64) NULL,'
            . 'entity_id VARCHAR(64) NULL,'
            . 'metadata LONGTEXT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_audit_created (created_at),'
            . 'INDEX idx_audit_action (action),'
            . 'CONSTRAINT fk_audit_staff_user FOREIGN KEY (staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
