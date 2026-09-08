<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080002';
    }

    public function description(): string
    {
        return 'Add local staff authentication sessions and password reset tokens';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE staff_users '
            . 'ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts, '
            . 'ADD COLUMN password_changed_at DATETIME NULL AFTER locked_until'
        );

        $pdo->exec(
            'CREATE TABLE staff_sessions ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'staff_user_id BIGINT UNSIGNED NOT NULL,'
            . 'token_hash CHAR(64) NOT NULL UNIQUE,'
            . 'ip_address VARCHAR(45) NULL,'
            . 'user_agent VARCHAR(500) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'revoked_at DATETIME NULL,'
            . 'INDEX idx_staff_sessions_user_active (staff_user_id, revoked_at, expires_at),'
            . 'CONSTRAINT fk_staff_sessions_user FOREIGN KEY (staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE staff_password_reset_tokens ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'staff_user_id BIGINT UNSIGNED NOT NULL,'
            . 'token_hash CHAR(64) NOT NULL UNIQUE,'
            . 'created_at DATETIME NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'used_at DATETIME NULL,'
            . 'INDEX idx_staff_password_reset_user (staff_user_id, used_at, expires_at),'
            . 'CONSTRAINT fk_staff_password_reset_user FOREIGN KEY (staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
