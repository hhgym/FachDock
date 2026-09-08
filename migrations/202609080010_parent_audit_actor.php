<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080010';
    }

    public function description(): string
    {
        return 'Allow parent contacts as audit actors';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE audit_log ADD COLUMN parent_contact_id BIGINT UNSIGNED NULL AFTER staff_user_id, '
            . 'ADD INDEX idx_audit_parent_contact (parent_contact_id), '
            . 'ADD CONSTRAINT fk_audit_parent_contact FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id)'
        );
    }
};
