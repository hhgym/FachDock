<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080006';
    }

    public function description(): string
    {
        return 'Add annual fee, parent change limit and temporary correction reopening to school years';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE school_years '
            . 'ADD annual_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER new_booking_opens_on, '
            . 'ADD max_parent_changes TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER annual_fee_cents, '
            . 'ADD closed_at DATETIME NULL AFTER max_parent_changes, '
            . 'ADD reopened_until DATETIME NULL AFTER closed_at, '
            . 'ADD reopen_reason VARCHAR(500) NULL AFTER reopened_until, '
            . 'ADD reopened_by_staff_user_id BIGINT UNSIGNED NULL AFTER reopen_reason, '
            . 'ADD INDEX idx_school_year_reopened_until (reopened_until), '
            . 'ADD CONSTRAINT fk_school_year_reopened_by_staff '
            . 'FOREIGN KEY (reopened_by_staff_user_id) REFERENCES staff_users(id)'
        );
    }
};
