<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Migration\MigrationRunner;
use FachDock\Payment\AdminPaymentTerminationService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminPaymentTerminationServiceIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?string $databaseName = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $prefix = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test');

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->databaseName = $prefix . '_payment_termination_' . bin2hex(random_bytes(4));
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseName === null) {
            return;
        }
        $host = (string) getenv('TEST_DB_HOST');
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $this->pdo = null;
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
        $this->databaseName = null;
    }

    public function testOpenCheckoutCanBeTerminatedAndReservationIsRestored(): void
    {
        (new AdminPaymentTerminationService($this->pdo()))->terminate(1, 'Checkout versehentlich gestartet', 'Ada Admin');

        $payment = $this->row('SELECT status, checkout_url, failure_code, failure_message FROM payments WHERE id = 1');
        self::assertSame('expired', $payment['status']);
        self::assertNull($payment['checkout_url']);
        self::assertSame('admin_terminated', $payment['failure_code']);
        self::assertStringContainsString('Ada Admin', (string) $payment['failure_message']);

        $reservation = $this->row('SELECT status, payment_grace_expires_at FROM locker_reservations WHERE id = 1');
        self::assertSame('active', $reservation['status']);
        self::assertNull($reservation['payment_grace_expires_at']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM payment_attempt_slots')->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM reservation_slots')->fetchColumn());
    }

    public function testConfirmedPaymentCannotBeTerminated(): void
    {
        $this->pdo()->exec("UPDATE payments SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = 1");

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nur noch nicht bestätigte offene Zahlungsvorgänge');
        (new AdminPaymentTerminationService($this->pdo()))->terminate(1, 'Soll nicht gehen', 'Ada Admin');
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T3', 'Dreier', 3, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'Gruppe A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec(
            "INSERT INTO locker_reservations (id, student_id, school_year_id, locker_id, projected_grade, status, expires_at, payment_grace_expires_at, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 1, 8, 'payment_running', DATE_ADD(NOW(), INTERVAL 20 MINUTE), DATE_ADD(NOW(), INTERVAL 40 MINUTE), '{}', NOW(), NOW())"
        );
        $pdo->exec('INSERT INTO reservation_slots (reservation_id, school_year_id, student_id, locker_id, created_at) VALUES (1, 1, 1, 1, NOW())');
        $pdo->exec(
            "INSERT INTO payments (id, reservation_id, booking_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, proration_months, stripe_checkout_session_id, checkout_url, created_at, updated_at) VALUES (1, 1, NULL, 1, 'stripe', 'checkout_open', 2400, 'EUR', 2400, 12, 'cs_test_open', 'https://checkout.stripe.test/session', NOW(), NOW())"
        );
        $pdo->exec('INSERT INTO payment_attempt_slots (reservation_id, payment_id, created_at) VALUES (1, 1, NOW())');
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo()->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }
}
