<?php

declare(strict_types=1);

namespace FachDock\Tests\Release;

use DomainException;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\BookingSelfServiceService;
use FachDock\Booking\BookingService;
use FachDock\Booking\FeeCalculator;
use FachDock\Booking\LockerRecommendationRanker;
use FachDock\Booking\LockerRecommendationService;
use FachDock\Booking\ParentBookingService;
use FachDock\Booking\ProjectedGradeResolver;
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

final class BookingJourneyEndToEndTest extends TestCase
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
        $this->databaseName = $prefix . '_release_journey_' . bin2hex(random_bytes(4));
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

    public function testParentCanSelectPayChangeAndRenewLockerAcrossCoreModules(): void
    {
        $pdo = $this->pdo();
        $parent = new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 1);
        $evaluator = new AllocationRuleEvaluator($pdo);
        $gradeResolver = new ProjectedGradeResolver();
        $ranker = new LockerRecommendationRanker();
        $reservations = new ReservationService($pdo, 15, 30, $evaluator, $gradeResolver);
        $recommendations = new LockerRecommendationService($pdo, $evaluator, $ranker, $gradeResolver);
        $bookingPortal = new ParentBookingService($pdo, $recommendations, $reservations, $ranker);

        $selection = $bookingPortal->selection($parent, 1, 1, 3);
        self::assertSame(8, $selection['projected_grade']);
        self::assertCount(2, $selection['available']);
        self::assertCount(2, $selection['recommended']);

        $reservationId = $bookingPortal->reserve($parent, 1, 1, 1);
        self::assertGreaterThan(0, $reservationId);
        self::assertSame('active', (string) $pdo->query('SELECT status FROM locker_reservations WHERE id = ' . $reservationId)->fetchColumn());

        $gateway = new ReleaseCandidateStripeGateway();
        $payments = new StripePaymentService(
            $pdo,
            $gateway,
            $reservations,
            new BookingService($pdo),
            new FeeCalculator(),
            'https://fachdock.test',
            'EUR',
            30,
        );
        $payment = $payments->start($parent, $reservationId);
        self::assertSame('https://checkout.stripe.test/release/' . $payment->paymentId, $payment->checkoutUrl());
        self::assertSame('payment_running', (string) $pdo->query('SELECT status FROM locker_reservations WHERE id = ' . $reservationId)->fetchColumn());

        $payload = json_encode([
            'id' => 'evt_release_paid',
            'type' => 'checkout.session.completed',
            'checkout_session_id' => 'cs_release_' . $payment->paymentId,
            'payment_status' => 'paid',
            'payment_intent_id' => 'pi_release_1',
        ], JSON_THROW_ON_ERROR);
        $payments->handleWebhook($payload, 'sig_release');

        $bookingId = (int) $pdo->query('SELECT booking_id FROM payments WHERE id = ' . $payment->paymentId)->fetchColumn();
        self::assertGreaterThan(0, $bookingId);
        self::assertSame('active', (string) $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $bookingId)->fetchColumn());

        $selfService = new BookingSelfServiceService($pdo, $evaluator, 14);
        $selfService->changeLocker($parent, $bookingId, 2, 2);
        self::assertSame(2, (int) $pdo->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $bookingId)->fetchColumn());

        $renewedBookingId = $selfService->renew($parent, $bookingId, 2, false);
        self::assertNotSame($bookingId, $renewedBookingId);
        self::assertSame('payment_due', (string) $pdo->query('SELECT status FROM bookings WHERE id = ' . $renewedBookingId)->fetchColumn());
        self::assertSame(9, (int) $pdo->query('SELECT projected_grade FROM bookings WHERE id = ' . $renewedBookingId)->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $renewedBookingId)->fetchColumn());
        self::assertSame($bookingId, (int) $pdo->query('SELECT previous_booking_id FROM bookings WHERE id = ' . $renewedBookingId)->fetchColumn());

        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = {$bookingId} AND event_type = 'locker_changed'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = {$bookingId} AND event_type = 'renewed'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM stripe_webhook_events WHERE stripe_event_id = 'evt_release_paid' AND status = 'processed'")->fetchColumn());
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Hauptgebäude', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T1', 'Einzer', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'Gruppe A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW()), (2, 1, 1, 2, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW()), (2, 2, 1, 'A-02-1', 1, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW()), (2, '2027/28', '2027-08-01', '2028-07-31', 'future', '2026-01-01', 3000, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())");
        $pdo->exec('INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())');
    }
}

final class ReleaseCandidateStripeGateway implements StripeGateway
{
    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string {
        unset($email, $name);

        return $existingCustomerId ?? 'cus_release_' . $parentContactId;
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
        unset($reservationId, $customerId, $amountCents, $currency, $description, $successUrl, $cancelUrl, $expiresAt);

        return new StripeCheckoutSession(
            'cs_release_' . $paymentId,
            'https://checkout.stripe.test/release/' . $paymentId,
        );
    }

    /** @throws JsonException */
    public function verifyWebhook(string $payload, string $signature): StripeWebhookEvent
    {
        if ($signature !== 'sig_release') {
            throw new DomainException('Ungültige Releasesignatur.');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new DomainException('Ungültiges Releaseevent.');
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
