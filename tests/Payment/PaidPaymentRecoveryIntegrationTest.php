<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use FachDock\Booking\BookingService;
use FachDock\Migration\MigrationRunner;
use FachDock\Payment\PaidPaymentRecoveryService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaidPaymentRecoveryIntegrationTest extends TestCase
{
    public function testPaidManualReviewIsConvertedExactlyOnce(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->seed($pdo);

        $recovery = new PaidPaymentRecoveryService($pdo, new BookingService($pdo));
        self::assertSame(1, $recovery->recover(1));
        self::assertSame(1, $recovery->recover(1));

        self::assertSame('paid', $pdo->query('SELECT status FROM payments WHERE id = 1')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT booking_id FROM payments WHERE id = 1')->fetchColumn());
        self::assertSame('converted', $pdo->query('SELECT status FROM locker_reservations WHERE id = 1')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM locker_occupancies')->fetchColumn());
        self::assertFalse($pdo->query('SELECT failure_code FROM payments WHERE id = 1')->fetchColumn());
    }

    private function database(): PDO
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }

        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_recovery';

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private function seed(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'EG', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T1', 'Typ', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, active, created_at, updated_at) VALUES (1, 1, 'A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2027/28', '2027-08-01', '2028-07-31', 'future', '2026-01-01', 2400, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO locker_reservations (id, student_id, school_year_id, locker_id, projected_grade, status, expires_at, payment_grace_expires_at, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 1, 9, 'payment_running', DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_SUB(NOW(), INTERVAL 1 MINUTE), '{}', NOW(), NOW())");
        $pdo->exec('INSERT INTO reservation_slots (reservation_id, school_year_id, student_id, locker_id, created_at) VALUES (1, 1, 1, 1, NOW())');
        $pdo->exec("INSERT INTO payments (id, reservation_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, proration_months, stripe_checkout_session_id, stripe_payment_intent_id, failure_code, failure_message, created_at, updated_at, paid_at) VALUES (1, 1, 1, 'stripe', 'manual_review', 2400, 'EUR', 2400, 12, 'cs_test_recovery', 'pi_test_recovery', 'paid_booking_failed', 'fixture', NOW(), NOW(), NOW())");
    }
}
