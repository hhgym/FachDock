<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\BookingLifecycleAdminService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookingLifecycleAdminServiceIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_lifecycle_admin_' . bin2hex(random_bytes(4));
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

    public function testEventsCanBeLoadedByBookingAndRelatedBookingWithNativePreparedStatements(): void
    {
        $service = new BookingLifecycleAdminService(
            $this->pdo(),
            new AllocationRuleEvaluator($this->pdo()),
        );

        $sourceEvents = $service->events(1);
        $relatedEvents = $service->events(2);

        self::assertCount(1, $sourceEvents);
        self::assertCount(1, $relatedEvents);
        self::assertSame('renewed', $sourceEvents[0]['event_type']);
        self::assertSame(2, (int) $sourceEvents[0]['related_booking_id']);
        self::assertSame((int) $sourceEvents[0]['id'], (int) $relatedEvents[0]['id']);
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW()), (2, '2027/28', '2027-08-01', '2028-07-31', 'future', '2027-01-01', 3000, NOW(), NOW())");
        $pdo->exec("INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, student_snapshot, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 'active', 9, '2026-08-01', '2027-07-31', 'staff', NULL, 2400, 2400, 12, NULL, '{}', '{}', NOW(), NOW()), (2, 1, 2, 'payment_due', 10, '2027-08-01', '2028-07-31', 'staff', NULL, 3000, 3000, 12, NULL, '{}', '{}', NOW(), NOW())");
        $pdo->exec("INSERT INTO booking_lifecycle_events (booking_id, event_type, related_booking_id, old_locker_id, new_locker_id, effective_on, reason, actor_type, actor_id, created_at) VALUES (1, 'renewed', 2, NULL, NULL, '2027-08-01', 'Verlängert', 'staff', NULL, NOW())");
    }
}
