<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080007';
    }

    public function description(): string
    {
        return 'Add parent contacts, historical student links and one-time magic link tokens';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE parent_contacts ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'email VARCHAR(255) NOT NULL UNIQUE,'
            . 'first_name VARCHAR(255) NULL,'
            . 'last_name VARCHAR(255) NULL,'
            . "status VARCHAR(24) NOT NULL DEFAULT 'pending',"
            . 'verified_at DATETIME NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_parent_contacts_status (active, status),'
            . 'INDEX idx_parent_contacts_verified (verified_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE parent_student_links ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'parent_contact_id BIGINT UNSIGNED NOT NULL,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . "link_origin VARCHAR(32) NOT NULL DEFAULT 'staff',"
            . 'started_at DATETIME NOT NULL,'
            . 'ended_at DATETIME NULL,'
            . 'created_by_staff_user_id BIGINT UNSIGNED NULL,'
            . 'ended_by_staff_user_id BIGINT UNSIGNED NULL,'
            . 'end_reason VARCHAR(255) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_parent_student_history (parent_contact_id, student_id, started_at),'
            . 'INDEX idx_student_parent_history (student_id, started_at),'
            . 'CONSTRAINT fk_parent_student_link_parent FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id),'
            . 'CONSTRAINT fk_parent_student_link_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_parent_student_link_created_staff FOREIGN KEY (created_by_staff_user_id) REFERENCES staff_users(id),'
            . 'CONSTRAINT fk_parent_student_link_ended_staff FOREIGN KEY (ended_by_staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE parent_student_link_slots ('
            . 'parent_contact_id BIGINT UNSIGNED NOT NULL,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'link_id BIGINT UNSIGNED NOT NULL UNIQUE,'
            . 'created_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (parent_contact_id, student_id),'
            . 'CONSTRAINT fk_parent_student_slot_parent FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id),'
            . 'CONSTRAINT fk_parent_student_slot_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_parent_student_slot_link FOREIGN KEY (link_id) REFERENCES parent_student_links(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE parent_magic_links ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'parent_contact_id BIGINT UNSIGNED NOT NULL,'
            . 'token_hash CHAR(64) NOT NULL UNIQUE,'
            . 'purpose VARCHAR(32) NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'consumed_at DATETIME NULL,'
            . 'requested_ip VARCHAR(45) NULL,'
            . 'user_agent VARCHAR(500) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_parent_magic_link_parent (parent_contact_id, purpose, created_at),'
            . 'INDEX idx_parent_magic_link_expiry (expires_at, consumed_at),'
            . 'CONSTRAINT fk_parent_magic_link_parent FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
