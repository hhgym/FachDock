<?php

declare(strict_types=1);

namespace FachDock\Tests\SchoolYear;

use DateTimeImmutable;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Migration\MigrationRunner;
use FachDock\SchoolYear\SchoolYearTransitionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchoolYearTransitionIntegrationTest extends TestCase
{
    public function testReminderAndRolloverAreIdempotent(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);
        $service = new SchoolYearTransitionService(
            $pdo,
            new AllocationRuleEvaluator($pdo),
            new MailQueueService($pdo, new MailTemplateRenderer()),
            'https://fachdock.example.test',
            'Testschule',
        );

        $preview = $service->preview(1, 2);
        self::assertSame(1, $preview['active_source']);
        self::assertSame(1, $preview['unrenewed']);
        self::assertSame(1, $preview['same_locker_possible']);

        self::assertSame(1, $service->queueReminderStage(1, 2, 'june_01'));
        self::assertSame(0, $service->queueReminderStage(1, 2, 'june_01'));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM school_year_reminder_dispatches')->fetchColumn());

        $summary = $service->applyRollover(1, 2, 'staff', 1, new DateTimeImmutable('2026-08-01'));
        self::assertSame(1, $summary['ended']);
        self::assertSame(1, $summary['without_target_booking']);
        self::assertSame('ended', (string) $pdo->query('SELECT status FROM bookings WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM booking_slots WHERE booking_id = 1')->fetchColumn());
        self::assertSame('school_year_ended', (string) $pdo->query('SELECT event_type FROM booking_lifecycle_events WHERE booking_id = 1')->fetchColumn());
        self::assertSame('closed', (string) $pdo->query('SELECT status FROM school_years WHERE id = 1')->fetchColumn());
        self::assertSame('current', (string) $pdo->query('SELECT status FROM school_years WHERE id = 2')->fetchColumn());

        $second = $service->applyRollover(1, 2, 'staff', 1, new DateTimeImmutable('2026-08-02'));
        self::assertSame($summary, $second);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM school_year_transition_runs WHERE status = 'completed'")->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());
    }

    public function testDailyTickCatchesUpMissedReminderStages(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);
        $service = new SchoolYearTransitionService(
            $pdo,
            new AllocationRuleEvaluator($pdo),
            new MailQueueService($pdo, new MailTemplateRenderer()),
            'https://fachdock.example.test',
            'Testschule',
        );

        $result = $service->dailyTick(new DateTimeImmutable('2026-07-21'));
        self::assertSame(3, $result['reminders']);
        self::assertSame(['june_01', 'july_01', 'july_20'], $result['reminder_stages']);
        self::assertSame(0, $result['rollovers']);

        $again = $service->dailyTick(new DateTimeImmutable('2026-07-22'));
        self::assertSame(0, $again['reminders']);
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
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Ada', 'Test', '6-1', 6, 1, NOW(), NOW())");
        $pdo->exec(
            "INSERT INTO school_years (id, label, starts_on, ends_on, status, annual_fee_cents, new_booking_opens_on, created_at, updated_at) VALUES "
            . "(1, '25/26', '2025-08-01', '2026-07-31', 'current', 1000, '2025-06-01', NOW(), NOW()),"
            . "(2, '26/27', '2026-08-01', '2027-07-31', 'future', 1000, '2026-06-01', NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at) "
            . "VALUES (1, 1, 1, 'active', 6, '2025-08-01', '2026-07-31', 'staff', 1000, 1000, '{}', '{}', NOW(), NOW())"
        );
        $pdo->exec("INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (1, 1, 1, NOW())");
        $pdo->exec("INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 1, 1, NOW())");
        $pdo->exec("INSERT INTO locker_assignment_history (booking_id, school_year_id, locker_id, starts_at, reason, actor_type, locker_snapshot, created_at) VALUES (1, 1, 1, '2025-08-01 00:00:00', 'initial', 'staff', '{}', NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())");
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_school_year_transition';
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
}
