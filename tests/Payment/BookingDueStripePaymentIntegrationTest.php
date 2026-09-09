<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Booking\BookingPaymentAdminService;
use FachDock\Mail\BookingNotificationService;
use FachDock\Mail\MailQueueService;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Payment\BookingDueStripePaymentService;
use FachDock\Payment\BookingPaymentGateway;
use FachDock\Payment\StripeCheckoutSession;
use FachDock\Payment\StripeWebhookEvent;
use JsonException;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BookingDueStripePaymentIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_booking_due_' . bin2hex(random_bytes(4));
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

        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->seedPaymentDueBooking();
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

    public function testPaymentDueBookingIsPaidExactlyOnceAndQueuesNotifications(): void
    {
        $gateway = new BookingDueTestStripeGateway();
        $notifications = $this->notifications();
        $service = $this->service($gateway);

        $result = $service->start($this->parent(), 1);
        self::assertSame(1, $result->paymentId);
        self::assertSame('https://checkout.stripe.test/booking/1', $result->checkoutUrl());

        $payment = $this->row('SELECT reservation_id, booking_id, status, amount_cents FROM payments WHERE id = 1');
        self::assertNull($payment['reservation_id']);
        self::assertSame(1, (int) $payment['booking_id']);
        self::assertSame('checkout_open', $payment['status']);
        self::assertSame(2400, (int) $payment['amount_cents']);
        self::assertSame('payment_due', $this->pdo()->query('SELECT status FROM bookings WHERE id = 1')->fetchColumn());

        $payload = json_encode([
            'id' => 'evt_booking_paid_1',
            'type' => 'checkout.session.completed',
            'checkout_session_id' => 'cs_booking_1',
            'payment_status' => 'paid',
            'payment_intent_id' => 'pi_booking_1',
        ], JSON_THROW_ON_ERROR);

        self::assertTrue($service->handleWebhookIfBookingPayment($payload, 'sig_test'));
        $notifications->stripeEventProcessed($payload);
        self::assertTrue($service->handleWebhookIfBookingPayment($payload, 'sig_test'));
        $notifications->stripeEventProcessed($payload);

        $booking = $this->row('SELECT status, payment_due_at FROM bookings WHERE id = 1');
        self::assertSame('active', $booking['status']);
        self::assertNull($booking['payment_due_at']);

        $payment = $this->row('SELECT status, stripe_payment_intent_id FROM payments WHERE id = 1');
        self::assertSame('paid', $payment['status']);
        self::assertSame('pi_booking_1', $payment['stripe_payment_intent_id']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM booking_payment_attempt_slots')->fetchColumn());

        self::assertSame(1, $this->queuedTemplateCount('payment_received'));
        self::assertSame(1, $this->queuedTemplateCount('booking_confirmed'));

        $adminPayments = (new BookingPaymentAdminService($this->pdo()))->payments(1, 'paid', '');
        self::assertCount(1, $adminPayments);
        self::assertNull($adminPayments[0]['reservation_id']);
        self::assertSame(1, (int) $adminPayments[0]['booking_id']);
    }

    public function testExpiredBookingPaymentRemainsPayableAndCanBeRetried(): void
    {
        $service = $this->service(new BookingDueTestStripeGateway());
        $notifications = $this->notifications();
        $service->start($this->parent(), 1);

        $payload = json_encode([
            'id' => 'evt_booking_expired_1',
            'type' => 'checkout.session.expired',
            'checkout_session_id' => 'cs_booking_1',
            'payment_status' => 'unpaid',
            'payment_intent_id' => null,
        ], JSON_THROW_ON_ERROR);

        self::assertTrue($service->handleWebhookIfBookingPayment($payload, 'sig_test'));
        $notifications->stripeEventProcessed($payload);

        self::assertSame('payment_due', $this->pdo()->query('SELECT status FROM bookings WHERE id = 1')->fetchColumn());
        self::assertSame('expired', $this->pdo()->query('SELECT status FROM payments WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM booking_payment_attempt_slots')->fetchColumn());
        self::assertSame(1, $this->queuedTemplateCount('payment_checkout_expired'));

        $retry = $service->start($this->parent(), 1);
        self::assertSame(2, $retry->paymentId);
    }

    public function testBuTRejectionQueuesDecisionAndScheduledReminder(): void
    {
        $templateCount = $this->pdo()->query(
            "SELECT COUNT(*) FROM mail_templates WHERE template_key IN ("
            . "'booking_confirmed','payment_received','payment_failed','payment_checkout_expired',"
            . "'payment_received_booking_pending','but_request_received','but_approved',"
            . "'but_rejected_payment_due','payment_due_reminder')"
        )->fetchColumn();
        self::assertSame(9, (int) $templateCount);

        $this->notifications()->butRejected(1);
        self::assertSame(1, $this->queuedTemplateCount('but_rejected_payment_due'));
        self::assertSame(1, $this->queuedTemplateCount('payment_due_reminder'));

        $row = $this->row(
            "SELECT q.available_at, b.payment_due_at FROM mail_queue q "
            . 'INNER JOIN mail_templates t ON t.id = q.mail_template_id '
            . "CROSS JOIN bookings b WHERE t.template_key = 'payment_due_reminder' AND b.id = 1 LIMIT 1"
        );
        $available = new \DateTimeImmutable((string) $row['available_at']);
        $due = new \DateTimeImmutable((string) $row['payment_due_at']);
        self::assertSame(3, (int) $available->diff($due)->format('%a'));
    }

    private function service(BookingPaymentGateway $gateway): BookingDueStripePaymentService
    {
        return new BookingDueStripePaymentService(
            $this->pdo(),
            $gateway,
            'https://fachdock.test',
            'EUR',
            30,
        );
    }

    private function notifications(): BookingNotificationService
    {
        return new BookingNotificationService(
            $this->pdo(),
            new MailQueueService($this->pdo()),
            'https://fachdock.test',
            'Heinrich-Hertz-Gymnasium',
            new NullLogger(),
        );
    }

    private function parent(): AuthenticatedParent
    {
        return new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 1);
    }

    private function queuedTemplateCount(string $templateKey): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id '
            . 'WHERE t.template_key = :template_key'
        );
        $statement->execute(['template_key' => $templateKey]);

        return (int) $statement->fetchColumn();
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo()->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function seedPaymentDueBooking(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'EG', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T1', 'Typ', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, active, created_at, updated_at) VALUES (1, 1, 'A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2027/28', '2027-08-01', '2028-07-31', 'future', '2026-01-01', 2400, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())");
        $pdo->exec('INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec(
            "INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, student_snapshot, rule_snapshot, created_at, updated_at, payment_due_at) VALUES (1, 1, 1, 'payment_due', 9, '2027-08-01', '2028-07-31', 'parent', 1, 2400, 2400, 12, 'but_rejected', '{}', '{}', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY))"
        );
        $pdo->exec('INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec('INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 1, 1, NOW())');
    }
}

final class BookingDueTestStripeGateway implements BookingPaymentGateway
{
    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string {
        unset($email, $name);

        return $existingCustomerId ?? 'cus_booking_' . $parentContactId;
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
        unset($paymentId, $reservationId, $customerId, $amountCents, $currency, $description, $successUrl, $cancelUrl, $expiresAt);
        throw new DomainException('Reservation checkout is not used in this test.');
    }

    public function createBookingCheckoutSession(
        int $paymentId,
        int $bookingId,
        string $customerId,
        int $amountCents,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): StripeCheckoutSession {
        unset($bookingId, $customerId, $amountCents, $currency, $description, $successUrl, $cancelUrl, $expiresAt);

        return new StripeCheckoutSession(
            'cs_booking_' . $paymentId,
            'https://checkout.stripe.test/booking/' . $paymentId,
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
