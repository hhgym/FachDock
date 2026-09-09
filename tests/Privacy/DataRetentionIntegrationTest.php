<?php

declare(strict_types=1);

namespace FachDock\Tests\Privacy;

use DateTimeImmutable;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Migration\MigrationRunner;
use FachDock\Privacy\DataRetentionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DataRetentionIntegrationTest extends TestCase
{
    public function testEligiblePersonalDataIsAnonymizedButBusinessRecordsRemain(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);

        $queue = new MailQueueService($pdo, new MailTemplateRenderer());
        $queueId = $queue->enqueue('parent_login', 'parent@example.test', 'Erika Muster', [
            'school_name' => 'Testschule',
            'parent_name_suffix' => ' Erika Muster',
            'magic_link' => 'https://example.test/secret',
            'expires_minutes' => 15,
        ]);
        $pdo->exec("UPDATE mail_queue SET created_at = '2024-01-01 00:00:00', updated_at = '2024-01-01 00:00:00' WHERE id = " . $queueId);
        $queue->recordHistory($queueId, 'sent', null);
        $pdo->exec("UPDATE mail_delivery_history SET recorded_at = '2024-01-01 00:00:00' WHERE mail_queue_id = " . $queueId);

        $service = new DataRetentionService($pdo);
        $preview = $service->preview(new DateTimeImmutable('2026-09-09'));
        self::assertSame('2023-09-09', $preview['cutoff_date']);
        self::assertSame(1, $preview['students']);
        self::assertSame(1, $preview['mails']);

        $summary = $service->anonymize(1, new DateTimeImmutable('2026-09-09'));
        self::assertSame(1, $summary['students']);
        self::assertSame(1, $summary['parents']);
        self::assertSame(1, $summary['mails']);
        self::assertSame(1, $summary['mail_history']);
        self::assertSame(1, $summary['incidents']);

        $student = $pdo->query('SELECT matrikelnummer, first_name, last_name, email, active FROM students WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($student);
        self::assertStringStartsWith('ANON-1-', (string) $student['matrikelnummer']);
        self::assertSame('Anonymisiert', $student['first_name']);
        self::assertNull($student['email']);
        self::assertSame('0', (string) $student['active']);
        self::assertSame('{"anonymized":true}', (string) $pdo->query('SELECT student_snapshot FROM bookings WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM bookings WHERE id = 1')->fetchColumn());

        $parent = $pdo->query('SELECT email, first_name, last_name, status, active, stripe_customer_id FROM parent_contacts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($parent);
        self::assertSame('anon-parent-1@invalid.local', $parent['email']);
        self::assertNull($parent['first_name']);
        self::assertSame('anonymized', $parent['status']);
        self::assertSame('0', (string) $parent['active']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM parent_magic_links WHERE parent_contact_id = 1')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT revoked_at FROM parent_sessions WHERE parent_contact_id = 1')->fetchColumn());

        self::assertSame('redacted@invalid.local', (string) $pdo->query('SELECT recipient_email FROM mail_queue WHERE id = ' . $queueId)->fetchColumn());
        self::assertNull($pdo->query('SELECT html_body FROM mail_queue WHERE id = ' . $queueId)->fetchColumn());
        self::assertNull($pdo->query('SELECT metadata FROM audit_log WHERE id = 1')->fetchColumn());
        self::assertSame('[anonymisiert]', (string) $pdo->query('SELECT description FROM locker_incidents WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM privacy_anonymization_runs WHERE status = 'completed'")->fetchColumn());
    }

    private function fixtures(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, created_at, updated_at) VALUES (1, 'admin', 'Admin', 'admin@example.test', 'hash', 'administrator', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'H', 'Haus', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'EG', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T', 'Typ', 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'A', 'A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, email, active, created_at, updated_at) VALUES (1, '1001', 'Ada', 'Alt', '12', 12, 'ada@example.test', 0, '2020-01-01', '2020-01-01')");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, annual_fee_cents, new_booking_opens_on, created_at, updated_at) VALUES (1, '20/21', '2020-08-01', '2021-07-31', 'closed', 1000, '2020-06-01', '2020-01-01', '2021-08-01')");
        $pdo->exec("INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, annual_fee_cents, charged_fee_cents, student_snapshot, rule_snapshot, created_at, updated_at, ended_at) VALUES (1, 1, 1, 'ended', 12, '2020-08-01', '2021-07-31', 'staff', 1000, 1000, '{\"first_name\":\"Ada\"}', '{}', '2020-08-01', '2021-08-01', '2021-08-01')");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, stripe_customer_id, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', '2020-01-01', 'cus_old', 1, '2020-01-01', '2020-01-01')");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', '2020-01-01', '2020-01-01')");
        $pdo->exec("INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, '2020-01-01')");
        $pdo->exec("INSERT INTO parent_sessions (parent_contact_id, token_hash, created_at, last_seen_at, expires_at) VALUES (1, REPEAT('a',64), '2020-01-01', '2020-01-01', '2030-01-01')");
        $pdo->exec("INSERT INTO parent_magic_links (parent_contact_id, token_hash, purpose, expires_at, created_at) VALUES (1, REPEAT('b',64), 'login', '2030-01-01', '2020-01-01')");
        $pdo->exec("INSERT INTO locker_incidents (id, locker_id, booking_id, student_id, category, status, priority, description, resolution_note, reported_by_type, reported_by_id, reporter_email, reporter_name, opened_at, resolved_at, created_at, updated_at) VALUES (1, 1, 1, 1, 'defect', 'resolved', 'normal', 'Ada hat ein Problem', 'erledigt', 'parent', 1, 'parent@example.test', 'Erika Muster', '2020-09-01', '2020-09-02', '2020-09-01', '2020-09-02')");
        $pdo->exec("INSERT INTO audit_log (id, actor_type, staff_user_id, parent_contact_id, action, entity_type, entity_id, metadata, created_at) VALUES (1, 'staff', 1, NULL, 'old.action', 'student', '1', '{\"name\":\"Ada\"}', '2020-01-01')");
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_privacy';
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
