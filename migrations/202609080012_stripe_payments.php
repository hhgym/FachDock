<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080012';
    }

    public function description(): string
    {
        return 'Add Stripe customers, payment attempts and webhook idempotency';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE parent_contacts '
            . 'ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER verified_at, '
            . 'ADD UNIQUE INDEX uq_parent_stripe_customer (stripe_customer_id)'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payments ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'reservation_id BIGINT UNSIGNED NOT NULL,'
            . 'booking_id BIGINT UNSIGNED NULL,'
            . 'parent_contact_id BIGINT UNSIGNED NOT NULL,'
            . "provider VARCHAR(32) NOT NULL DEFAULT 'stripe',"
            . 'status VARCHAR(32) NOT NULL,'
            . 'amount_cents INT UNSIGNED NOT NULL,'
            . "currency CHAR(3) NOT NULL DEFAULT 'EUR',"
            . 'annual_fee_cents INT UNSIGNED NOT NULL,'
            . 'proration_months TINYINT UNSIGNED NOT NULL,'
            . 'stripe_checkout_session_id VARCHAR(255) NULL,'
            . 'stripe_payment_intent_id VARCHAR(255) NULL,'
            . 'checkout_url TEXT NULL,'
            . 'failure_code VARCHAR(128) NULL,'
            . 'failure_message VARCHAR(1000) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'paid_at DATETIME NULL,'
            . 'failed_at DATETIME NULL,'
            . 'UNIQUE KEY uq_payment_checkout_session (stripe_checkout_session_id),'
            . 'UNIQUE KEY uq_payment_intent (stripe_payment_intent_id),'
            . 'INDEX idx_payments_parent (parent_contact_id, created_at),'
            . 'INDEX idx_payments_reservation (reservation_id, created_at),'
            . 'INDEX idx_payments_booking (booking_id),'
            . 'INDEX idx_payments_status (status, updated_at),'
            . 'CONSTRAINT fk_payment_reservation FOREIGN KEY (reservation_id) REFERENCES locker_reservations(id),'
            . 'CONSTRAINT fk_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_payment_parent FOREIGN KEY (parent_contact_id) REFERENCES parent_contacts(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payment_attempt_slots ('
            . 'reservation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . 'payment_id BIGINT UNSIGNED NOT NULL UNIQUE,'
            . 'created_at DATETIME NOT NULL,'
            . 'CONSTRAINT fk_payment_slot_reservation FOREIGN KEY (reservation_id) REFERENCES locker_reservations(id),'
            . 'CONSTRAINT fk_payment_slot_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stripe_webhook_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'stripe_event_id VARCHAR(255) NOT NULL UNIQUE,'
            . 'event_type VARCHAR(128) NOT NULL,'
            . 'status VARCHAR(32) NOT NULL,'
            . 'payment_id BIGINT UNSIGNED NULL,'
            . 'received_at DATETIME NOT NULL,'
            . 'processed_at DATETIME NULL,'
            . 'error_message VARCHAR(1000) NULL,'
            . 'INDEX idx_stripe_event_status (status, received_at),'
            . 'CONSTRAINT fk_stripe_event_payment FOREIGN KEY (payment_id) REFERENCES payments(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
