<?php

declare(strict_types=1);

namespace FachDock\Tests\Auth;

use DomainException;
use FachDock\Auth\StaffSessionService;
use FachDock\Auth\StaffUserManagementService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class StaffUserManagementIntegrationTest extends TestCase
{
    public function testCreateDeactivateReactivateDeleteAndAnonymizeLifecycle(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);

        $sessions = new StaffSessionService($pdo, 480, 60);
        $service = new StaffUserManagementService($pdo, $sessions, 12);

        $createdId = $service->create(
            'neu.manager',
            'Neue Verwaltung',
            'manager@example.test',
            'locker_manager',
            'SicheresPasswort123!',
            'SicheresPasswort123!',
        );
        $created = $pdo->query('SELECT username, password_hash, active FROM staff_users WHERE id = ' . $createdId)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($created);
        self::assertSame('neu.manager', $created['username']);
        self::assertNotSame('SicheresPasswort123!', $created['password_hash']);
        self::assertSame('1', (string) $created['active']);

        $pdo->exec(
            'INSERT INTO staff_sessions (staff_user_id, token_hash, created_at, last_seen_at, expires_at) '
            . 'VALUES (' . $createdId . ", REPEAT('a', 64), NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        );
        $service->deactivate($createdId, 1);
        self::assertSame('0', (string) $pdo->query('SELECT active FROM staff_users WHERE id = ' . $createdId)->fetchColumn());
        self::assertNotNull($pdo->query('SELECT revoked_at FROM staff_sessions WHERE staff_user_id = ' . $createdId)->fetchColumn());

        $service->reactivate($createdId);
        self::assertSame('1', (string) $pdo->query('SELECT active FROM staff_users WHERE id = ' . $createdId)->fetchColumn());
        self::assertNull($pdo->query('SELECT deactivated_at FROM staff_users WHERE id = ' . $createdId)->fetchColumn());

        $service->deactivate($createdId, 1);
        $service->delete($createdId, 1);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM staff_users WHERE id = ' . $createdId)->fetchColumn());

        $service->deactivate(3, 1);
        try {
            $service->delete(3, 1);
            self::fail('A historically referenced account must not be deleted.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('revisionsrelevante Historie', $exception->getMessage());
        }

        $service->anonymize(3, 1);
        $anonymized = $pdo->query(
            'SELECT username, display_name, email, active, anonymized_at, last_login_at FROM staff_users WHERE id = 3'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($anonymized);
        self::assertSame('anonymized-3', $anonymized['username']);
        self::assertSame('Anonymisiertes Konto #3', $anonymized['display_name']);
        self::assertSame('anonymized-3@invalid.local', $anonymized['email']);
        self::assertSame('0', (string) $anonymized['active']);
        self::assertNotNull($anonymized['anonymized_at']);
        self::assertNull($anonymized['last_login_at']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM audit_log WHERE staff_user_id = 3')->fetchColumn());
    }

    public function testCurrentAccountAndLastAdministratorAreProtected(): void
    {
        $pdo = $this->database('protection');
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->fixtures($pdo);

        $service = new StaffUserManagementService($pdo, new StaffSessionService($pdo, 480, 60), 12);

        try {
            $service->deactivate(1, 1);
            self::fail('The current account must not be deactivated.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('eigene Konto', $exception->getMessage());
        }

        $service->deactivate(2, 1);
        try {
            $service->deactivate(1, 999);
            self::fail('The last active administrator must remain active.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('aktives Administratorkonto', $exception->getMessage());
        }
    }

    private function fixtures(PDO $pdo): void
    {
        $pdo->exec(
            'INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, last_login_at, created_at, updated_at) VALUES '
            . "(1, 'admin1', 'Admin Eins', 'admin1@example.test', 'hash', 'administrator', 1, NOW(), NOW(), NOW()),"
            . "(2, 'admin2', 'Admin Zwei', 'admin2@example.test', 'hash', 'administrator', 1, NOW(), NOW(), NOW()),"
            . "(3, 'alt.manager', 'Alte Verwaltung', 'old@example.test', 'hash', 'locker_manager', 1, NOW(), NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO audit_log (actor_type, staff_user_id, action, entity_type, entity_id, created_at) '
            . "VALUES ('staff', 3, 'historic.action', 'system', 'test', NOW())"
        );
    }

    private function database(string $suffix = 'lifecycle'): PDO
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_staff_' . $suffix;
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
