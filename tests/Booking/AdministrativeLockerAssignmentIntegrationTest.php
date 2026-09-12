<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DomainException;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\LockerRecommendationRanker;
use FachDock\Booking\LockerRecommendationService;
use FachDock\Booking\ProjectedGradeResolver;
use FachDock\Booking\ReservationService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdministrativeLockerAssignmentIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_admin_assignment_' . bin2hex(random_bytes(4));
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
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

    public function testAdministrativeAssignmentCreatesActiveZeroChargeBooking(): void
    {
        $bookingId = $this->reservations()->assignWithoutPayment(1, 1, 1, 99);

        $booking = $this->row(
            'SELECT status, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, '
            . 'fee_exemption_type FROM bookings WHERE id = ' . $bookingId,
        );
        self::assertSame('active', $booking['status']);
        self::assertSame('staff', $booking['initiated_by_type']);
        self::assertSame(99, (int) $booking['initiated_by_id']);
        self::assertSame(3000, (int) $booking['annual_fee_cents']);
        self::assertSame(0, (int) $booking['charged_fee_cents']);
        self::assertSame('administrative_assignment', $booking['fee_exemption_type']);
        self::assertSame(
            1,
            (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $bookingId)->fetchColumn(),
        );
        self::assertSame(
            'administrative_assignment',
            $this->pdo()->query(
                'SELECT reason FROM locker_assignment_history WHERE booking_id = ' . $bookingId . ' AND ends_at IS NULL',
            )->fetchColumn(),
        );
        self::assertSame(
            'converted',
            $this->pdo()->query('SELECT status FROM locker_reservations ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM reservation_slots')->fetchColumn());
    }

    public function testOverviewDistinguishesOccupiedReservedFreeAndUnavailableLockers(): void
    {
        $this->reservations()->assignWithoutPayment(1, 1, 1, 99);
        $this->reservations()->reserve(2, 1, 2, null, true);

        $overview = $this->recommendations()->lockerOverview(1);
        $byId = [];
        foreach ($overview as $locker) {
            $byId[(int) $locker['locker_id']] = $locker;
        }

        self::assertSame('occupied', $byId[1]['availability_status']);
        self::assertSame('Max', $byId[1]['occupied_first_name']);
        self::assertSame('Muster', $byId[1]['occupied_last_name']);
        self::assertSame('8-1', $byId[1]['occupied_class_name']);

        self::assertSame('reserved', $byId[2]['availability_status']);
        self::assertSame('Anna', $byId[2]['reserved_first_name']);
        self::assertSame('Beispiel', $byId[2]['reserved_last_name']);
        self::assertSame('7-1', $byId[2]['reserved_class_name']);

        self::assertSame('free', $byId[3]['availability_status']);
        self::assertSame('unavailable', $byId[4]['availability_status']);
    }

    public function testStudentWithExistingBookingCannotReceiveAnotherReservation(): void
    {
        $this->reservations()->assignWithoutPayment(1, 1, 1, 99);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bereits eine aktive Buchung');
        $this->reservations()->reserve(1, 1, 3, null, true);
    }

    private function reservations(): ReservationService
    {
        return new ReservationService(
            $this->pdo(),
            15,
            30,
            new AllocationRuleEvaluator($this->pdo()),
            new ProjectedGradeResolver(),
        );
    }

    private function recommendations(): LockerRecommendationService
    {
        return new LockerRecommendationService(
            $this->pdo(),
            new AllocationRuleEvaluator($this->pdo()),
            new LockerRecommendationRanker(),
            new ProjectedGradeResolver(),
        );
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

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T4', 'Vierer', 4, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, active, created_at, updated_at) VALUES (1, 1, 'A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec(
            'INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES '
            . "(1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW()), "
            . "(2, 1, 2, 'A-01-2', 0, 1, 1, 'operational', NOW(), NOW()), "
            . "(3, 1, 3, 'A-01-3', 1, 1, 1, 'operational', NOW(), NOW()), "
            . "(4, 1, 4, 'A-01-4', 0, 0, 1, 'operational', NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES '
            . "(1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW()), "
            . "(2, '1002', 'Anna', 'Beispiel', '7-1', 7, 1, NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) '
            . "VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 3000, NOW(), NOW())"
        );
    }
}
