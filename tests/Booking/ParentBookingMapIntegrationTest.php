<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\LockerRecommendationRanker;
use FachDock\Booking\LockerRecommendationService;
use FachDock\Booking\ParentBookingMapService;
use FachDock\Booking\ParentBookingService;
use FachDock\Booking\ProjectedGradeResolver;
use FachDock\Booking\ReservationService;
use FachDock\FloorPlan\FloorPlanService;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use PHPUnit\Framework\TestCase;

final class ParentBookingMapIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $root;
    private ParentBookingService $bookings;
    private ParentBookingMapService $maps;
    private AuthenticatedParent $parent;
    private int $planId;

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures();

        $this->root = sys_get_temp_dir() . '/fachdock-booking-map-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->root . '/storage', 0770, true));
        $source = $this->root . '/source.png';
        self::assertNotFalse(file_put_contents($source, 'trusted-fixture'));

        $floorPlans = new FloorPlanService($this->pdo, $this->root);
        $this->planId = $floorPlans->createFromFile(1, 'Buchungsplan', $source, 'plan.png', 'image/png', 1);
        $floorPlans->setPlacement($this->planId, 1, 10, 10, 10, 8, 1);
        $floorPlans->setPlacement($this->planId, 2, 30, 10, 10, 8, 1);

        $evaluator = new AllocationRuleEvaluator($this->pdo);
        $ranker = new LockerRecommendationRanker();
        $gradeResolver = new ProjectedGradeResolver();
        $recommendations = new LockerRecommendationService($this->pdo, $evaluator, $ranker, $gradeResolver);
        $reservations = new ReservationService($this->pdo, 15, 30, $evaluator, $gradeResolver);
        $this->bookings = new ParentBookingService($this->pdo, $recommendations, $reservations, $ranker);
        $this->maps = new ParentBookingMapService($this->bookings, $floorPlans);
        $this->parent = new AuthenticatedParent(1, 'parent@example.test', 'Paula', 'Parent', 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testMapUsesAuthoritativeRulesAndAvailability(): void
    {
        $selection = $this->maps->selection($this->parent, 1, 1, null, $this->planId, 3);
        self::assertSame(7, $selection['projected_grade']);
        self::assertSame($this->planId, $selection['selected_plan_id']);

        $lockers = $this->lockersById($selection);
        self::assertSame('selectable', $lockers[1]['booking_status']);
        self::assertTrue($lockers[1]['can_select']);
        self::assertTrue($lockers[1]['recommended']);
        self::assertSame('restricted', $lockers[2]['booking_status']);
        self::assertFalse($lockers[2]['can_select']);
        self::assertSame('occupied', $lockers[3]['booking_status']);
        self::assertSame('unavailable', $lockers[4]['booking_status']);
    }

    public function testActiveReservationIsMarkedSelectedAndCanBeChanged(): void
    {
        $reservationId = $this->bookings->reserve($this->parent, 1, 1, 1);
        self::assertGreaterThan(0, $reservationId);

        $selection = $this->maps->selection($this->parent, 1, 1, null, $this->planId, 3);
        $lockers = $this->lockersById($selection);
        self::assertSame('selected', $lockers[1]['booking_status']);
        self::assertFalse($lockers[1]['can_select']);
        self::assertSame(1, $selection['active_reservation']['locker_id']);
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<int, array<string, mixed>>
     */
    private function lockersById(array $selection): array
    {
        self::assertIsArray($selection['plan']);
        $result = [];
        foreach ($selection['plan']['groups'] as $group) {
            foreach ($group['lockers'] as $locker) {
                $result[(int) $locker['id']] = $locker;
            }
        }

        return $result;
    }

    private function fixtures(): void
    {
        $this->pdo->exec(
            'INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, created_at, updated_at) '
            . "VALUES (1, 'admin', 'Admin Test', 'admin@example.test', 'hash', 'administrator', 1, NOW(), NOW())"
        );
        $this->pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'H', 'Hauptgebäude', 1, NOW(), NOW())");
        $this->pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $this->pdo->exec(
            'INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES '
            . "(1, 1, 'A', 'Bereich A', 1, NOW(), NOW()), (2, 1, 'B', 'Bereich B', 1, NOW(), NOW())"
        );
        $this->pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'STD2', 'Standard 2', 2, 1, NOW(), NOW())");
        $this->pdo->exec(
            'INSERT INTO cabinet_groups (id, area_id, code, name, active, structure_locked_at, created_at, updated_at) VALUES '
            . "(1, 1, 'A', 'Gruppe A', 1, NOW(), NOW(), NOW()), (2, 2, 'B', 'Gruppe B', 1, NOW(), NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES '
            . '(1, 1, 1, 1, 1, NOW(), NOW()), (2, 1, 1, 2, 1, NOW(), NOW()), (3, 2, 1, 1, 1, NOW(), NOW())'
        );
        $this->pdo->exec(
            'INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES '
            . "(1, 1, 1, 'A-01-1', 1, 1, 1, 'operational', NOW(), NOW()), "
            . "(2, 3, 1, 'B-01-1', 0, 1, 1, 'operational', NOW(), NOW()), "
            . "(3, 2, 1, 'A-02-1', 0, 1, 1, 'operational', NOW(), NOW()), "
            . "(4, 2, 2, 'A-02-2', 0, 0, 1, 'defective', NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES '
            . "(1, '1001', 'Ada', 'Test', '7-1', 7, 1, NOW(), NOW()), "
            . "(2, '1002', 'Max', 'Belegt', '7-2', 7, 1, NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO school_years (id, label, starts_on, ends_on, status, annual_fee_cents, new_booking_opens_on, created_at, updated_at) '
            . "VALUES (1, '26/27', '2026-08-01', '2027-07-31', 'current', 1200, '2026-06-01', NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) '
            . "VALUES (1, 'parent@example.test', 'Paula', 'Parent', 'verified', NOW(), 1, NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_by_staff_user_id, created_at) '
            . "VALUES (1, 1, 1, 'staff', NOW(), 1, NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())'
        );
        $this->pdo->exec(
            'INSERT INTO allocation_rules (name, version, rule_kind, min_grade, max_grade, building_id, floor_id, area_id, cabinet_group_id, weight, priority, active, created_at, updated_at) '
            . "VALUES ('Klasse 7 nur Gruppe A', 1, 'hard_allow', 7, 7, NULL, NULL, NULL, 1, 0, 10, 1, NOW(), NOW())"
        );
        $this->pdo->exec(
            'INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at) '
            . "VALUES (1, 2, 1, 'active', 7, '2026-08-01', '2027-07-31', 'staff', 1200, 1200, '{}', '{}', NOW(), NOW())"
        );
        $this->pdo->exec('INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (1, 1, 2, NOW())');
        $this->pdo->exec('INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 3, 1, NOW())');
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_booking_map';
        $admin = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
