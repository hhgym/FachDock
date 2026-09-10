<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090007';
    }

    public function description(): string
    {
        return 'Add external OpenID Connect identities and sessions';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE oidc_identities ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'issuer VARCHAR(255) NOT NULL,'
            . 'subject VARCHAR(255) NOT NULL,'
            . 'uuid VARCHAR(128) NULL,'
            . 'account_name VARCHAR(190) NULL,'
            . 'display_name VARCHAR(255) NULL,'
            . 'email VARCHAR(255) NULL,'
            . "identity_type ENUM('student','teacher','pending') NOT NULL DEFAULT 'pending',"
            . "assignment_source ENUM('automatic','manual') NOT NULL DEFAULT 'automatic',"
            . 'student_id BIGINT UNSIGNED NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'last_login_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_oidc_identity_subject (issuer, subject),'
            . 'UNIQUE KEY uq_oidc_identity_uuid (issuer, uuid),'
            . 'INDEX idx_oidc_identity_student (student_id),'
            . 'INDEX idx_oidc_identity_type (identity_type, active),'
            . 'CONSTRAINT fk_oidc_identity_student FOREIGN KEY (student_id) REFERENCES students(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE oidc_sessions ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'identity_id BIGINT UNSIGNED NOT NULL,'
            . 'token_hash CHAR(64) NOT NULL UNIQUE,'
            . 'ip_address VARCHAR(45) NULL,'
            . 'user_agent VARCHAR(500) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'revoked_at DATETIME NULL,'
            . 'INDEX idx_oidc_sessions_identity (identity_id, revoked_at, expires_at),'
            . 'CONSTRAINT fk_oidc_sessions_identity FOREIGN KEY (identity_id) REFERENCES oidc_identities(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
