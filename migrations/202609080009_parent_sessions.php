<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080009';
    }

    public function description(): string
    {
        return 'Add revocable parent portal sessions';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE parent_sessions ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'parent_contact_id BIGINT UNSIGNED NOT NULL,'
            . 'token_hash CHAR(64) NOT NULL UNIQUE,'
            . 'ip_address VARCHAR(45) NULL,'
            . 'user_agent VARCHAR(500) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'last_seen_at DATETIME NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'revoked_at DATETIME NULL,'
            . 'INDEX idx_parent_sessions_contact (parent_contact_id, revoked_at, expires_at),'
            . 'CONSTRAINT fk_parent_sessions_contact FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
