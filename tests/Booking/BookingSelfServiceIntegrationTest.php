<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DomainException;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\BookingSelfServiceService;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookingSelfServiceIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_self_service_' . bin2hex(random_bytes(4));
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

    public function testParentLockerChangesAreLimitedPerBookingYear(): void
    {
        $service = $this->service();
        $parent = $this->parent();

        $service->changeLocker($parent, 1, 2, 2);
        $service->changeLocker($parent, 1, 3, 2);

        self::assertSame(3, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
        self::assertSame(2, (int) $this->pdo()->query("SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = 1 AND event_type = 'locker_changed' AND actor_type = 'parent'")->fetchColumn());
        $bookings = $service->bookingsForParent($parent, 2);
        self::assertSame(0, $bookings[0]['changes_remaining']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Wechsel-Limit');
        $service->changeLocker($parent, 1, 1, 2);
    }

    public function testAdministratorPreviewChangeDoesNotConsumeParentLimit(): void
    {
        $service = $this->service();
        $parent = $this->parent();
        $service->changeLocker($parent, 1, 2, 1);

        $preview = new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 0, true, 99);
        $service->changeLocker($preview, 1, 3, 1);

        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = 1 AND event_type = 'locker_changed' AND actor_type = 'parent'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = 1 AND event_type = 'locker_changed' AND actor_type = 'staff'")->fetchColumn());
        self::assertSame(3, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
    }

    public function testRenewalReusesCurrentLockerWhenAllowedAndFree(): void
    {
        $this->pdo()->exec('UPDATE bookings SET projected_grade = 8 WHERE id = 1');
        $this->pdo()->exec("UPDATE students SET grade = 8, class_name = '8-1' WHERE id = 1");

        $newBookingId = $this->service()->renew($this->parent(), 1, 2, false);
        $booking = $this->row('SELECT projected_grade, previous_booking_id, status FROM bookings WHERE id = ' . $newBookingId);

        self::assertSame(9, (int) $booking['projected_grade']);
        self::assertSame(1, (int) $booking['previous_booking_id']);
        self::assertSame('payment_due', $booking['status']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $newBookingId)->fetchColumn());
    }

    public function testTransitionFromGradeSixToSevenForcesDifferentLocker(): void
    {
        $this->pdo()->exec(
            "INSERT INTO allocation_rules (name, version, rule_kind, min_grade, max_grade, building_id, floor_id, area_id, cabinet_group_id, weight, priority, valid_from_school_year_id, valid_until_school_year_id, notes, active, created_at, updated_at) "
            . "VALUES ('Jahrgang 7 Bereich B', 1, 'hard_allow', 7, 7, NULL, NULL, NULL, 2, 0, 10, NULL, NULL, NULL, 1, NOW(), NOW())"
        );

        $plans = $this->service()->renewalPlans($this->parent(), 1);
        self::assertCount(1, $plans);
        self::assertTrue((bool) $plans[0]['requires_change']);
        self::assertSame(7, (int) $plans[0]['projected_grade']);
        self::assertSame(2, (int) $plans[0]['locker_id']);

        $newBookingId = $this->service()->renew($this->parent(), 1, 2, false);
        self::assertSame(2, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $newBookingId)->fetchColumn());
        $event = $this->row("SELECT old_locker_id, new_locker_id, actor_type FROM booking_lifecycle_events WHERE booking_id = 1 AND event_type = 'renewed'");
        self::assertSame(1, (int) $event['old_locker_id']);
        self::assertSame(2, (int) $event['new_locker_id']);
        self::assertSame('parent', $event['actor_type']);
    }

    public function testOccupiedCurrentLockerInTargetYearUsesAlternative(): void
    {
        $this->pdo()->exec('UPDATE bookings SET projected_grade = 8 WHERE id = 1');
        $this->pdo()->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (2, '1002', 'Ada', 'Andere', '9-1', 9, 1, NOW(), NOW())");
        $this->pdo()->exec("INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, student_snapshot, rule_snapshot, created_at, updated_at) VALUES (2, 2, 2, 'active', 9, '2027-08-01', '2028-07-31', 'staff', NULL, 3000, 3000, 12, '{}', '{}', NOW(), NOW())");
        $this->pdo()->exec('INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (2, 2, 2, NOW())');
        $this->pdo()->exec('INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (2, 1, 2, NOW())');

        $newBookingId = $this->service()->renew($this->parent(), 1, 2, false);
        self::assertSame(2, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $newBookingId)->fetchColumn());
    }

    public function testGradeSevenRenewalFailsWhenNoAlternativeLockerExists(): void
    {
        $this->pdo()->exec('UPDATE lockers SET bookable = 0 WHERE id IN (2, 3)');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Klassenstufe 7');
        $this->service()->renew($this->parent(), 1, 2, false);
    }

    private function service(): BookingSelfServiceService
    {
        return new BookingSelfServiceService($this->pdo(), new AllocationRuleEvaluator($this->pdo()), 14);
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
        $row = $this->pdo()->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW()), (2, 1, 'S', 'Süd', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T1', 'Einzer', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, active, created_at, updated_at) VALUES (1, 1, 'A', 1, NOW(), NOW()), (2, 2, 'B', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW()), (2, 2, 1, 1, 1, NOW(), NOW()), (3, 2, 1, 2, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW()), (2, 2, 1, 'B-01-1', 0, 1, 1, 'operational', NOW(), NOW()), (3, 3, 1, 'B-02-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '6-1', 6, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW()), (2, '2027/28', '2027-08-01', '2028-07-31', 'future', '2027-01-01', 3000, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())");
        $pdo->exec('INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec("INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, student_snapshot, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 'active', 6, '2026-08-01', '2027-07-31', 'parent', 1, 2400, 2400, 12, NULL, '{}', '{}', NOW(), NOW())");
        $pdo->exec('INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec('INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec("INSERT INTO locker_assignment_history (booking_id, school_year_id, locker_id, starts_at, reason, actor_type, actor_id, locker_snapshot, created_at) VALUES (1, 1, 1, NOW(), 'initial_booking', 'parent', 1, '{}', NOW())");
    }
}
