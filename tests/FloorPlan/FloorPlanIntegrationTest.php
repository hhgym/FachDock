<?php

declare(strict_types=1);

namespace FachDock\Tests\FloorPlan;

use FachDock\FloorPlan\FloorPlanService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class FloorPlanIntegrationTest extends TestCase
{
    public function testPlanPlacementAndLiveAvailabilityArePersisted(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);

        $root = sys_get_temp_dir() . '/fachdock-floorplan-' . bin2hex(random_bytes(5));
        mkdir($root . '/storage', 0770, true);
        $source = $root . '/source.png';
        file_put_contents($source, 'trusted-fixture');

        try {
            $service = new FloorPlanService($pdo, $root);
            $planId = $service->createFromFile(1, 'Hauptflur', $source, 'plan.png', 'image/png', 1);
            $service->setPlacement($planId, 1, 12.5, 21.25, 10, 8, 1);

            $plan = $service->plan($planId, 1);
            self::assertSame('Hauptflur', $plan['title']);
            self::assertSame(1, $plan['floor_id']);
            self::assertCount(1, $plan['groups']);
            $group = $plan['groups'][0];
            self::assertTrue($group['placed']);
            self::assertSame(12.5, $group['x_percent']);
            self::assertSame(21.25, $group['y_percent']);
            self::assertSame(3, $group['total_count']);
            self::assertSame(1, $group['free_count']);
            self::assertSame(1, $group['occupied_count']);
            self::assertSame(1, $group['unavailable_count']);
            self::assertSame('warning', $group['marker_status']);
            self::assertNotNull($service->image($planId));

            $service->removePlacement($planId, 1);
            self::assertFalse($service->plan($planId, 1)['groups'][0]['placed']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function fixtures(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, created_at, updated_at) "
            . "VALUES (1, 'admin', 'Admin Test', 'admin@example.test', 'hash', 'administrator', 1, NOW(), NOW())"
        );
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'H', 'Hauptgebäude', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'Flur A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'STD3', 'Standard 3', 3, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, name, active, structure_locked_at, created_at, updated_at) VALUES (1, 1, 'A', 'Gruppe A', 1, NOW(), NOW(), NOW())");
        $pdo->exec("INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())");
        $pdo->exec(
            "INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES "
            . "(1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW()),"
            . "(2, 1, 2, 'A-01-2', 1, 1, 1, 'operational', NOW(), NOW()),"
            . "(3, 1, 3, 'A-01-3', 0, 0, 1, 'defective', NOW(), NOW())"
        );
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Ada', 'Test', '7-1', 7, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, annual_fee_cents, new_booking_opens_on, created_at, updated_at) VALUES (1, '26/27', '2026-08-01', '2027-07-31', 'current', 1000, '2026-06-01', NOW(), NOW())");
        $pdo->exec(
            "INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at) "
            . "VALUES (1, 1, 1, 'active', 7, '2026-08-01', '2027-07-31', 'staff', 1000, 1000, '{}', '{}', NOW(), NOW())"
        );
        $pdo->exec("INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 2, 1, NOW())");
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_floorplans';
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
