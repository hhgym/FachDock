<?php

declare(strict_types=1);

namespace FachDock\Tests\Privacy;

use DateTimeImmutable;
use FachDock\Migration\MigrationRunner;
use FachDock\Privacy\AccountLifecycleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountLifecycleIntegrationTest extends TestCase
{
    public function testStudentGracePeriodDeactivationAndAutomaticReturn(): void
    {
        $pdo = $this->database('student');
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->baseStaff($pdo);
        $this->student($pdo, 1, '1001', 0, '2026-07-01 00:00:00');
        $this->oidcIdentity($pdo, 1, 1);
        $pdo->exec(
            "INSERT INTO oidc_sessions (identity_id, token_hash, created_at, last_seen_at, expires_at) "
            . "VALUES (1, REPEAT('a', 64), '2026-09-01', '2026-09-01', '2027-01-01')"
        );

        $service = new AccountLifecycleService($pdo);
        self::assertSame([
            'student_deactivation_days' => 30,
            'student_anonymization_days' => 365,
            'parent_deactivation_days' => 1095,
            'parent_anonymization_days' => 1095,
        ], $service->settings());

        $result = $service->process(new DateTimeImmutable('2026-09-12'));
        self::assertSame(1, $result['students_deactivated']);
        self::assertSame(0, $result['students_anonymized']);
        self::assertSame('lifecycle', (string) $pdo->query('SELECT account_deactivation_source FROM students WHERE id = 1')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT active FROM oidc_identities WHERE id = 1')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT revoked_at FROM oidc_sessions WHERE identity_id = 1')->fetchColumn());
        self::assertNull($pdo->query('SELECT anonymized_at FROM students WHERE id = 1')->fetchColumn());

        $pdo->exec('UPDATE students SET active = 1 WHERE id = 1');
        $service->synchronizeLifecycleStarts();
        self::assertNull($pdo->query('SELECT account_deactivated_at FROM students WHERE id = 1')->fetchColumn());
        self::assertNull($pdo->query('SELECT inactive_since FROM students WHERE id = 1')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT active FROM oidc_identities WHERE id = 1')->fetchColumn());

        $service->deactivateStudent(1);
        $pdo->exec('UPDATE students SET active = 1 WHERE id = 1');
        $service->synchronizeLifecycleStarts();
        self::assertSame('manual', (string) $pdo->query('SELECT account_deactivation_source FROM students WHERE id = 1')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT account_deactivated_at FROM students WHERE id = 1')->fetchColumn());
    }

    public function testParentLifecycleStartsWhenLastChildBecomesInactive(): void
    {
        $pdo = $this->database('parent_start');
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->baseStaff($pdo);
        $this->student($pdo, 1, '2001', 0, '2025-08-20 10:00:00');
        $this->parent($pdo, 1, 'parent@example.test');
        $this->link($pdo, 1, 1, 1);

        $service = new AccountLifecycleService($pdo);
        $service->synchronizeLifecycleStarts();

        self::assertSame(
            '2025-08-20 10:00:00',
            (string) $pdo->query('SELECT lifecycle_started_at FROM parent_contacts WHERE id = 1')->fetchColumn(),
        );
        self::assertSame('1', (string) $pdo->query('SELECT active FROM parent_contacts WHERE id = 1')->fetchColumn());

        $pdo->exec('UPDATE students SET active = 1, inactive_since = NULL WHERE id = 1');
        $service->synchronizeLifecycleStarts();
        self::assertNull($pdo->query('SELECT lifecycle_started_at FROM parent_contacts WHERE id = 1')->fetchColumn());
        self::assertSame('1', (string) $pdo->query('SELECT active FROM parent_contacts WHERE id = 1')->fetchColumn());
    }

    public function testDueStudentAndParentDataAreAnonymizedAndActiveChildProtectsParent(): void
    {
        $pdo = $this->database('anonymize');
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->baseStaff($pdo);

        $this->student($pdo, 1, '3001', 0, '2022-01-01 00:00:00');
        $this->oidcIdentity($pdo, 1, 1);
        $this->parent($pdo, 1, 'old-parent@example.test');
        $this->link($pdo, 1, 1, 1);
        $pdo->exec(
            "INSERT INTO parent_sessions (parent_contact_id, token_hash, created_at, last_seen_at, expires_at) "
            . "VALUES (1, REPEAT('b', 64), '2022-01-01', '2022-01-01', '2030-01-01')"
        );
        $pdo->exec(
            "INSERT INTO parent_magic_links (parent_contact_id, token_hash, purpose, expires_at, created_at) "
            . "VALUES (1, REPEAT('c', 64), 'login', '2030-01-01', '2022-01-01')"
        );

        $this->student($pdo, 2, '3002', 1, null);
        $this->parent($pdo, 2, 'active-parent@example.test');
        $this->link($pdo, 2, 2, 2);

        $service = new AccountLifecycleService($pdo);
        $result = $service->process(new DateTimeImmutable('2026-09-12'));

        self::assertSame(1, $result['students_anonymized']);
        self::assertSame(1, $result['parents_anonymized']);
        self::assertSame('Anonymisiert', (string) $pdo->query('SELECT first_name FROM students WHERE id = 1')->fetchColumn());
        self::assertNotNull($pdo->query('SELECT anonymized_at FROM students WHERE id = 1')->fetchColumn());
        self::assertSame('pending', (string) $pdo->query('SELECT identity_type FROM oidc_identities WHERE id = 1')->fetchColumn());
        self::assertSame('0', (string) $pdo->query('SELECT active FROM oidc_identities WHERE id = 1')->fetchColumn());
        self::assertSame('anonymized', (string) $pdo->query('SELECT status FROM parent_contacts WHERE id = 1')->fetchColumn());
        self::assertSame('anon-parent-1@invalid.local', (string) $pdo->query('SELECT email FROM parent_contacts WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM parent_magic_links WHERE parent_contact_id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM parent_student_link_slots WHERE parent_contact_id = 1')->fetchColumn());

        self::assertSame('1', (string) $pdo->query('SELECT active FROM parent_contacts WHERE id = 2')->fetchColumn());
        self::assertNull($pdo->query('SELECT lifecycle_started_at FROM parent_contacts WHERE id = 2')->fetchColumn());
        self::assertNull($pdo->query('SELECT anonymized_at FROM parent_contacts WHERE id = 2')->fetchColumn());
    }

    public function testLifecycleDeadlinesAreConfigurable(): void
    {
        $pdo = $this->database('settings');
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $service = new AccountLifecycleService($pdo);

        $service->saveSettings(45, 400, 900, 1200);

        self::assertSame([
            'student_deactivation_days' => 45,
            'student_anonymization_days' => 400,
            'parent_deactivation_days' => 900,
            'parent_anonymization_days' => 1200,
        ], $service->settings());
    }

    private function baseStaff(PDO $pdo): void
    {
        $pdo->exec(
            "INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, created_at, updated_at) "
            . "VALUES (1, 'admin', 'Admin', 'admin@example.test', 'hash', 'administrator', 1, NOW(), NOW())"
        );
    }

    private function student(PDO $pdo, int $id, string $matrikelnummer, int $active, ?string $inactiveSince): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO students '
            . '(id, matrikelnummer, first_name, last_name, class_name, grade, email, active, inactive_since, access_code_hash, '
            . 'access_code_generated_at, created_at, updated_at) '
            . "VALUES (:id, :matrikel, :first, :last, '9-1', 9, :email, :active, :inactive_since, :access_hash, NOW(), NOW(), NOW())"
        );
        $statement->execute([
            'id' => $id,
            'matrikel' => $matrikelnummer,
            'first' => 'Schüler' . $id,
            'last' => 'Test' . $id,
            'email' => 'student' . $id . '@example.test',
            'active' => $active,
            'inactive_since' => $inactiveSince,
            'access_hash' => hash('sha256', 'student-code-' . $id),
        ]);
    }

    private function oidcIdentity(PDO $pdo, int $id, int $studentId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO oidc_identities '
            . '(id, issuer, subject, account_name, display_name, email, identity_type, assignment_source, student_id, active, created_at, updated_at) '
            . "VALUES (:id, 'https://idp.example.test', :subject, :account, :display, :email, 'student', 'automatic', :student, 1, NOW(), NOW())"
        );
        $statement->execute([
            'id' => $id,
            'subject' => 'student-sub-' . $id,
            'account' => 'student' . $id,
            'display' => 'Schüler ' . $id,
            'email' => 'student' . $id . '@example.test',
            'student' => $studentId,
        ]);
    }

    private function parent(PDO $pdo, int $id, string $email): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO parent_contacts '
            . '(id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) '
            . "VALUES (:id, :email, 'Eltern', :last_name, 'verified', NOW(), 1, NOW(), NOW())"
        );
        $statement->execute(['id' => $id, 'email' => $email, 'last_name' => 'Test' . $id]);
    }

    private function link(PDO $pdo, int $linkId, int $parentId, int $studentId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO parent_student_links '
            . "(id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (:link, :parent, :student, 'staff', NOW(), NOW())"
        );
        $statement->execute(['link' => $linkId, 'parent' => $parentId, 'student' => $studentId]);
        $slot = $pdo->prepare(
            'INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) '
            . 'VALUES (:parent, :student, :link, NOW())'
        );
        $slot->execute(['parent' => $parentId, 'student' => $studentId, 'link' => $linkId]);
    }

    private function database(string $suffix): PDO
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_account_lifecycle_' . $suffix;
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
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
