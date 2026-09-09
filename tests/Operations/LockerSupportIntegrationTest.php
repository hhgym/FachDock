<?php

declare(strict_types=1);

namespace FachDock\Tests\Operations;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffRole;
use FachDock\Location\LockerOperatingStatus;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Migration\MigrationRunner;
use FachDock\Operations\LockerIncidentStatus;
use FachDock\Operations\LockerSupportNotificationService;
use FachDock\Operations\LockerSupportService;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LockerSupportIntegrationTest extends TestCase
{
    public function testParentDefectReportBlocksFutureBookingAndResolutionIsNotified(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->resetFixtures($pdo);
        $this->fixtures($pdo);

        $queue = new MailQueueService($pdo, new MailTemplateRenderer());
        $support = new LockerSupportService(
            $pdo,
            new LockerSupportNotificationService(
                $queue,
                'https://fachdock.example.test',
                'Testschule',
                new NullLogger(),
            ),
        );
        $parent = new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 1);

        $incidentId = $support->reportFromParent(
            $parent,
            1,
            'defect',
            'Die Tür lässt sich nicht mehr vollständig schließen.',
        );
        self::assertGreaterThan(0, $incidentId);

        $incident = $support->incident($incidentId);
        self::assertSame('open', $incident['status']);
        self::assertSame('defect', $incident['category']);
        self::assertSame('A-01-1', $incident['locker_name']);
        self::assertSame('Ada Test', $incident['student_name']);

        $locker = $pdo->query('SELECT operating_status, bookable FROM lockers WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($locker);
        self::assertSame(LockerOperatingStatus::Defective->value, $locker['operating_status']);
        self::assertSame('0', (string) $locker['bookable']);

        $queuedTemplates = $pdo->query(
            'SELECT t.template_key FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id ORDER BY q.id'
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['locker_issue_received'], $queuedTemplates);

        $staff = new AuthenticatedStaff(
            1,
            'admin',
            'Admin Test',
            'admin@example.test',
            StaffRole::Administrator,
            1,
        );
        $support->updateIncident($staff, $incidentId, LockerIncidentStatus::Resolved->value, 'Tür nachgestellt und geprüft.');
        $resolved = $support->incident($incidentId);
        self::assertSame('resolved', $resolved['status']);
        self::assertSame('Tür nachgestellt und geprüft.', $resolved['resolution_note']);
        self::assertNotNull($resolved['resolved_at']);

        $queuedTemplates = $pdo->query(
            'SELECT t.template_key FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id ORDER BY q.id'
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['locker_issue_received', 'locker_issue_resolved'], $queuedTemplates);

        $support->updateLockerStatus(
            $staff,
            1,
            LockerOperatingStatus::Operational->value,
            true,
            'Reparatur abgeschlossen und Funktion geprüft.',
            $incidentId,
        );
        $locker = $pdo->query('SELECT operating_status, bookable FROM lockers WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($locker);
        self::assertSame('operational', $locker['operating_status']);
        self::assertSame('1', (string) $locker['bookable']);
        self::assertCount(2, $support->operationHistory(1));
    }

    public function testEmergencyOpeningIsDocumentedAndStudentCanSeeIncident(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->resetFixtures($pdo);
        $this->fixtures($pdo);

        $support = new LockerSupportService(
            $pdo,
            new LockerSupportNotificationService(
                new MailQueueService($pdo, new MailTemplateRenderer()),
                'https://fachdock.example.test',
                'Testschule',
                new NullLogger(),
            ),
        );
        $id = $support->reportFromStudent(
            1,
            1,
            'emergency_opening',
            'Ich bekomme das Fach nicht mehr auf und brauche meine Sachen.',
        );
        self::assertSame('urgent', $support->incident($id)['priority']);

        $staff = new AuthenticatedStaff(
            1,
            'locker',
            'Lena Locker',
            'locker@example.test',
            StaffRole::LockerManager,
            1,
        );
        $support->recordEmergencyOpening($staff, $id, 'Mit Notschlüssel geöffnet und anschließend wieder verschlossen.');

        $incident = $support->incident($id);
        self::assertSame('resolved', $incident['status']);
        self::assertStringContainsString('Notöffnung durchgeführt', (string) $incident['resolution_note']);
        self::assertCount(1, $support->incidentsForStudent(1));
        $events = $support->events($id);
        self::assertSame('reported', $events[0]['event_type']);
        self::assertSame('emergency_opening_performed', $events[1]['event_type']);
    }

    private function fixtures(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'H', 'Hauptgebäude', 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'Flur A', 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'STD3', 'Standard 3', 3, 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO cabinet_groups (id, area_id, code, name, active, structure_locked_at, created_at, updated_at) VALUES (1, 1, 'A', 'Gruppe A', 1, NOW(), NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) '
            . 'VALUES (1, 1, 1, 1, 1, NOW(), NOW())'
        );
        $pdo->exec(
            "INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, email, active, access_code_hash, access_code_generated_at, created_at, updated_at) VALUES (1, '1001', 'Ada', 'Test', '7-1', 7, 'ada@example.test', 1, 'fixturehash', NOW(), NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, created_at, updated_at) VALUES (1, '26/27', '2026-08-01', '2027-07-31', 'current', '2026-06-01', NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 'active', 7, '2026-08-01', '2027-07-31', 'staff', NULL, 1000, 1000, '{}', '{}', NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 1, 1, NOW())'
        );
        $pdo->exec(
            "INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())'
        );
    }

    private function resetFixtures(PDO $pdo): void
    {
        $tables = [
            'locker_operation_events',
            'locker_incident_events',
            'locker_incidents',
            'mail_delivery_history',
            'mail_queue',
            'parent_student_link_slots',
            'parent_student_links',
            'parent_contacts',
            'locker_occupancies',
            'booking_slots',
            'bookings',
            'school_years',
            'students',
            'lockers',
            'corpuses',
            'cabinet_groups',
            'corpus_type_positions',
            'corpus_types',
            'areas',
            'floors',
            'buildings',
        ];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_operations_support';
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }
}
