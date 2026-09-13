<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Booking\BookingService;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Payment\StripeCheckoutReader;
use FachDock\Payment\StripeCheckoutState;
use FachDock\Payment\StripeReturnReconciler;
use PDO;
use PHPUnit\Framework\TestCase;

final class StripeReturnReconcilerIntegrationTest extends TestCase
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

        $this->databaseName = $prefix . '_' . bin2hex(random_bytes(5));
        $admin->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $this->pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        $root = dirname(__DIR__, 2);
        (new MigrationRunner($this->pdo, $root . '/migrations'))->migrate();
        $this->seedContext();
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

    public function testPaidCheckoutReturnCreatesBookingWithoutWebhook(): void
    {
        $reader = new PaidCheckoutReader('cs_test_1', 'pi_test_return_1');
        $service = new StripeReturnReconciler($this->pdo(), $reader, new BookingService($this->pdo()));

        $service->reconcile($this->parent(), 1, 'cs_test_1');

        $payment = $this->row('SELECT status, booking_id, stripe_payment_intent_id FROM payments WHERE id = 1');
        self::assertSame('paid', $payment['status']);
        self::assertSame(1, (int) $payment['booking_id']);
        self::assertSame('pi_test_return_1', $payment['stripe_payment_intent_id']);

        $booking = $this->row('SELECT status, charged_fee_cents FROM bookings WHERE id = 1');
        self::assertSame('active', $booking['status']);
        self::assertSame(2400, (int) $booking['charged_fee_cents']);

        $reservation = $this->row('SELECT status FROM locker_reservations WHERE id = 1');
        self::assertSame('converted', $reservation['status']);

        $eventCount = $this->pdo()->query('SELECT COUNT(*) FROM stripe_webhook_events')->fetchColumn();
        self::assertSame(0, (int) $eventCount);
    }

    public function testReturnRejectsCheckoutSessionFromDifferentPayment(): void
    {
        $reader = new PaidCheckoutReader('cs_other', 'pi_other');
        $service = new StripeReturnReconciler($this->pdo(), $reader, new BookingService($this->pdo()));

        $this->expectException(DomainException::class);
        $service->reconcile($this->parent(), 1, 'cs_other');
    }

    private function parent(): AuthenticatedParent
    {
        return new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 1);
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo()->query($sql)->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function seedContext(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            'INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES '
            . "(1, 'A', 'Hauptgebäude', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES '
            . "(1, 1, 'EG', 'Erdgeschoss', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES '
            . "(1, 1, 'N', 'Nord', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES '
            . "(1, 'T1', 'Testkorpus', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES '
            . "(1, 1, 'G1', 'Gruppe 1', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES '
            . '(1, 1, 1, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $pdo->exec(
            'INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES '
            . "(1, 1, 1, 'A-001', 0, 1, 1, 'operational', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES '
            . "(1, '1001', 'Max', 'Muster', '8-1', 8, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES '
            . "(1, '2027/28', '2027-08-01', '2028-07-31', 'future', '2026-01-01', 2400, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES '
            . "(1, 'parent@example.test', 'Erika', 'Muster', 'verified', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES '
            . "(1, 1, 1, 'staff', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES '
            . '(1, 1, 1, CURRENT_TIMESTAMP)'
        );
        $pdo->exec(
            'INSERT INTO locker_reservations '
            . '(id, student_id, school_year_id, locker_id, projected_grade, status, expires_at, '
            . 'payment_grace_expires_at, rule_snapshot, created_at, updated_at) VALUES '
            . "(1, 1, 1, 1, 9, 'payment_running', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 HOUR), "
            . "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 HOUR), '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO reservation_slots (reservation_id, school_year_id, student_id, locker_id, created_at) '
            . 'VALUES (1, 1, 1, 1, CURRENT_TIMESTAMP)'
        );
        $pdo->exec(
            'INSERT INTO payments '
            . '(id, reservation_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, '
            . 'proration_months, stripe_checkout_session_id, checkout_url, created_at, updated_at) VALUES '
            . "(1, 1, 1, 'stripe', 'checkout_open', 2400, 'EUR', 2400, 12, 'cs_test_1', "
            . "'https://checkout.stripe.test/c/pay/1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO payment_attempt_slots (reservation_id, payment_id, created_at) '
            . 'VALUES (1, 1, CURRENT_TIMESTAMP)'
        );
    }
}

final readonly class PaidCheckoutReader implements StripeCheckoutReader
{
    public function __construct(
        private string $sessionId,
        private ?string $paymentIntentId,
    ) {
    }

    public function retrieveCheckoutState(string $sessionId): StripeCheckoutState
    {
        return new StripeCheckoutState($this->sessionId, 'paid', $this->paymentIntentId);
    }
}
