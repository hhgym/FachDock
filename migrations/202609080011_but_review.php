<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080011';
    }

    public function description(): string
    {
        return 'Add BuT exemption review metadata to bookings';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE bookings '
            . 'ADD COLUMN exemption_reviewed_at DATETIME NULL AFTER fee_exemption_type, '
            . 'ADD COLUMN exemption_reviewed_by_staff_user_id BIGINT UNSIGNED NULL AFTER exemption_reviewed_at, '
            . 'ADD COLUMN exemption_review_note TEXT NULL AFTER exemption_reviewed_by_staff_user_id, '
            . 'ADD COLUMN payment_due_at DATETIME NULL AFTER exemption_review_note, '
            . 'ADD INDEX idx_bookings_exemption_review (status, fee_exemption_type, created_at), '
            . 'ADD INDEX idx_bookings_payment_due (status, payment_due_at), '
            . 'ADD CONSTRAINT fk_booking_exemption_reviewer '
            . 'FOREIGN KEY (exemption_reviewed_by_staff_user_id) REFERENCES staff_users(id)'
        );
    }
};
