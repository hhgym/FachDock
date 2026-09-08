<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Booking\BookingService;
use FachDock\Booking\FeeCalculator;
use FachDock\Booking\ReservationService;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Payment\StripeCheckoutSession;
use FachDock\Payment\StripeGateway;
use FachDock\Payment\StripePaymentService;
use FachDock\Payment\StripeWebhookEvent;
use JsonException;
use PDO;
use PHPUnit\Framework\TestCase;

final class StripePaymentServiceIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?string $databaseName = null;
    private ?TestStripeGateway $gateway = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }

        $port = getenv('TEST_DB_PORT');
        $username = getenv('TEST_DB_USERNAME');
        $password = getenv('TEST_DB_PASSWORD');
        $prefix = getenv('TEST_DB_NAME_PREFIX');

        $port = is_string($port) && $port !== '' ? $port : '3306';
        $username = is_string($username) && $username !== '' ? $username : 'root';
        $password = is_string($password) ? $password : '';
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'fachdock_test';

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
        $this->seedBookingContext();
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

    public function testCheckoutStartCreatesPaymentAndLocksReservation(): void
    {
        $service = $this->service();
        $result = $service->start($this->parent(), 1);

        self::assertSame(1, $result->paymentId);
        self::assertNull($result->bookingId);
        self::assertSame('https://checkout.stripe.test/c/pay/1', $result->checkoutUrl());

        $payment = $this->row('SELECT * FROM payments WHERE id = 1');
        self::assertSame('checkout_open', $payment['status']);
        self::assertSame(2400, (int) $payment['amount_cents']);
        self::assertSame('EUR', $payment['currency']);
        self::assertSame('cs_test_1', $payment['stripe_checkout_session_id']);

        $reservation = $this->row('SELECT status, payment_grace_expires_at FROM locker_reservations WHERE id = 1');
        self::assertSame('payment_running', $reservation['status']);
        self::assertNotNull($reservation['payment_grace_expires_at']);

        $parent = $this->row('SELECT stripe_customer_id FROM parent_contacts WHERE id = 1');
        self::assertSame('cus_test_1', $parent['stripe_customer_id']);

        self::assertNotNull($this->gateway);
        self::assertSame('https://fachdock.test/parent/payment/return?payment_id=1&session_id={CHECKOUT_SESSION_ID}', $this->gateway->successUrl);
        self::assertSame('https://fachdock.test/parent/booking?student_id=1&school_year_id=1&payment_cancelled=1', $this->gateway->cancelUrl);
    }

    public function testPaidWebhookConvertsReservationExactlyOnce(): void
    {
        $service = $this->service();
        $result = $service->start($this->parent(), 1);
        self::assertSame(1, $result->paymentId);

        $payload = json_encode([
            'id' => 'evt_test_paid_1',
            'type' => 'checkout.session.completed',
            'checkout_session_id' => 'cs_test_1',
            'payment_status' => 'paid',
            'payment_intent_id' => 'pi_test_1',
        ], JSON_THROW_ON_ERROR);

        $service->handleWebhook($payload, 'sig_test');
        $service->handleWebhook($payload, 'sig_test');

        $payment = $this->row('SELECT status, booking_id, stripe_payment_intent_id FROM payments WHERE id = 1');
        self::assertSame('paid', $payment['status']);
        self::assertSame(1, (int) $payment['booking_id']);
        self::assertSame('pi_test_1', $payment['stripe_payment_intent_id']);

        $booking = $this->row('SELECT status, charged_fee_cents, fee_exemption_type FROM bookings WHERE id = 1');
        self::assertSame('active', $booking['status']);
        self::assertSame(2400, (int) $booking['charged_fee_cents']);
        self::assertNull($booking['fee_exemption_type']);

        $reservation = $this->row('SELECT status FROM locker_reservations WHERE id = 1');
        self::assertSame('converted', $reservation['status']);

        $occupancy = $this->row('SELECT booking_id FROM locker_occupancies WHERE school_year_id = 1 AND locker_id = 1');
        self::assertSame(1, (int) $occupancy['booking_id']);

        $eventCount = $this->pdo()->query(
            "SELECT COUNT(*) FROM stripe_webhook_events WHERE stripe_event_id = 'evt_test_paid_1'"
        )->fetchColumn();
        self::assertSame(1, (int) $eventCount);

        $bookingCount = $this->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
        self::assertSame(1, (int) $bookingCount);
    }

    public function testExpiredWebhookRestoresReservationWhenOriginalReservationIsStillValid(): void
    {
        $service = $this->service();
        $service->start($this->parent(), 1);

        $payload = json_encode([
            'id' => 'evt_test_expired_1',
            'type' => 'checkout.session.expired',
            'checkout_session_id' => 'cs_test_1',
            'payment_status' => 'unpaid',
            'payment_intent_id' => null,
        ], JSON_THROW_ON_ERROR);

        $service->handleWebhook($payload, 'sig_test');

        $payment = $this->row('SELECT status, failure_code FROM payments WHERE id = 1');
        self::assertSame('expired', $payment['status']);
        self::assertSame('stripe_checkout_expired', $payment['failure_code']);

        $reservation = $this->row('SELECT status, payment_grace_expires_at FROM locker_reservations WHERE id = 1');
        self::assertSame('active', $reservation['status']);
        self::assertNull($reservation['payment_grace_expires_at']);

        $slot = $this->row('SELECT reservation_id FROM reservation_slots WHERE reservation_id = 1');
        self::assertSame(1, (int) $slot['reservation_id']);
    }

    private function service(): StripePaymentService
    {
        $this->gateway = new TestStripeGateway();

        return new StripePaymentService(
            $this->pdo(),
            $this->gateway,
            new ReservationService($this->pdo(), 15, 30),
            new BookingService($this->pdo()),
            new FeeCalculator(),
            'https://fachdock.test',
            'EUR',
            30,
        );
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

    private function seedBookingContext(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            "INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES "
            . "(1, 'A', 'Hauptgebäude', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES "
            . "(1, 1, 'EG', 'Erdgeschoss', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES "
            . "(1, 1, 'N', 'Nord', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES "
            . "(1, 'T1', 'Testkorpus', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES "
            . "(1, 1, 'G1', 'Gruppe 1', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES "
            . "(1, 1, 1, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES "
            . "(1, 1, 1, 'A-001', 0, 1, 1, 'operational', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES "
            . "(1, '1001', 'Max', 'Muster', '8-1', 8, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES "
            . "(1, '2027/28', '2027-08-01', '2028-07-31', 'future', '2026-01-01', 2400, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES "
            . "(1, 'parent@example.test', 'Erika', 'Muster', 'verified', CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES "
            . "(1, 1, 1, 'staff', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            "INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES "
            . '(1, 1, 1, CURRENT_TIMESTAMP)'
        );
        $pdo->exec(
            "INSERT INTO locker_reservations (id, student_id, school_year_id, locker_id, projected_grade, status, expires_at, rule_snapshot, created_at, updated_at) VALUES "
            . "(1, 1, 1, 1, 9, 'active', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 HOUR), '{}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $pdo->exec(
            'INSERT INTO reservation_slots (reservation_id, school_year_id, student_id, locker_id, created_at) '
            . 'VALUES (1, 1, 1, 1, CURRENT_TIMESTAMP)'
        );
    }
}

final class TestStripeGateway implements StripeGateway
{
    public ?string $successUrl = null;
    public ?string $cancelUrl = null;

    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string {
        unset($email, $name);

        return $existingCustomerId ?? 'cus_test_' . $parentContactId;
    }

    public function createCheckoutSession(
        int $paymentId,
        int $reservationId,
        string $customerId,
        int $amountCents,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): StripeCheckoutSession {
        unset($reservationId, $customerId, $amountCents, $currency, $description, $expiresAt);
        $this->successUrl = $successUrl;
        $this->cancelUrl = $cancelUrl;

        return new StripeCheckoutSession(
            'cs_test_' . $paymentId,
            'https://checkout.stripe.test/c/pay/' . $paymentId,
        );
    }

    /** @throws JsonException */
    public function verifyWebhook(string $payload, string $signature): StripeWebhookEvent
    {
        if ($signature !== 'sig_test') {
            throw new DomainException('Ungültige Testsignatur.');
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new DomainException('Ungültiges Testevent.');
        }

        return new StripeWebhookEvent(
            (string) ($data['id'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['checkout_session_id'] ?? ''),
            (string) ($data['payment_status'] ?? ''),
            isset($data['payment_intent_id']) ? (string) $data['payment_intent_id'] : null,
        );
    }
}
