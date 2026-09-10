<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use FachDock\Booking\BookingPaymentAdminService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookingPaymentAdminPaginationIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_admin_pagination_' . bin2hex(random_bytes(4));
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

    public function testBookingPagesReturnAllFilteredRowsWithoutFixedResultCap(): void
    {
        $service = new BookingPaymentAdminService($this->pdo());

        $first = $service->bookingPage(1, null, '', 1, 50);
        self::assertSame(105, $first['total']);
        self::assertSame(3, $first['pages']);
        self::assertSame(1, $first['page']);
        self::assertCount(50, $first['items']);
        self::assertSame(105, (int) $first['items'][0]['id']);

        $last = $service->bookingPage(1, null, '', 3, 50);
        self::assertSame(3, $last['page']);
        self::assertCount(5, $last['items']);
        self::assertSame(5, (int) $last['items'][0]['id']);
        self::assertSame(1, (int) $last['items'][4]['id']);

        $filtered = $service->bookingPage(1, 'active', '105', 1, 50);
        self::assertSame(1, $filtered['total']);
        self::assertCount(1, $filtered['items']);
        self::assertSame(105, (int) $filtered['items'][0]['id']);
    }

    public function testPaymentPagesKeepStatusAndSearchFilters(): void
    {
        $service = new BookingPaymentAdminService($this->pdo());

        $paid = $service->paymentPage(1, 'paid', '', 2, 25);
        self::assertSame(53, $paid['total']);
        self::assertSame(3, $paid['pages']);
        self::assertSame(2, $paid['page']);
        self::assertCount(25, $paid['items']);
        foreach ($paid['items'] as $payment) {
            self::assertSame('paid', $payment['status']);
        }

        $searched = $service->paymentPage(null, null, 'pi-page-104', 1, 50);
        self::assertSame(1, $searched['total']);
        self::assertCount(1, $searched['items']);
        self::assertSame(104, (int) $searched['items'][0]['id']);
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'EG', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T', 'Typ', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Pagination', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'pagination@example.test', 'Erika', 'Pagination', 'verified', NOW(), 1, NOW(), NOW())");

        $booking = $pdo->prepare(
            'INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at) '
            . "VALUES (:id, 1, 1, 'active', 8, '2026-08-01', '2027-07-31', 'staff', 2400, 2400, '{}', '{}', NOW(), NOW())"
        );
        $reservation = $pdo->prepare(
            'INSERT INTO locker_reservations (id, student_id, school_year_id, locker_id, projected_grade, status, expires_at, rule_snapshot, created_at, updated_at) '
            . "VALUES (:id, 1, 1, 1, 8, 'converted', DATE_ADD(NOW(), INTERVAL 1 HOUR), '{}', NOW(), NOW())"
        );
        $payment = $pdo->prepare(
            'INSERT INTO payments (id, reservation_id, booking_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, proration_months, stripe_checkout_session_id, stripe_payment_intent_id, created_at, updated_at, paid_at) '
            . "VALUES (:id, :reservation_id, :booking_id, 1, 'stripe', :status, 2400, 'EUR', 2400, 12, :session_id, :intent_id, NOW(), NOW(), NOW())"
        );

        for ($id = 1; $id <= 105; $id++) {
            $booking->execute(['id' => $id]);
            $reservation->execute(['id' => $id]);
            $payment->execute([
                'id' => $id,
                'reservation_id' => $id,
                'booking_id' => $id,
                'status' => $id % 2 === 0 ? 'failed' : 'paid',
                'session_id' => 'cs-page-' . $id,
                'intent_id' => 'pi-page-' . $id,
            ]);
        }
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }
}
