<?php

declare(strict_types=1);

namespace FachDock\Tests\Parent;

use FachDock\Audit\AuditLogger;
use FachDock\Migration\MigrationRunner;
use FachDock\Parent\ParentSessionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ParentAdminPreviewIntegrationTest extends TestCase
{
    public function testUnverifiedParentCanBePreviewedOnlyByActiveAdministratorAndIsAuditedAsStaff(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->resetFixtures($pdo);

        $staffToken = 'admin-preview-token';
        $staffTokenHash = hash('sha256', $staffToken);
        $pdo->exec(
            'INSERT INTO staff_users (id, username, display_name, email, password_hash, role, active, created_at, updated_at) '
            . "VALUES (1, 'admin', 'Admin Test', 'admin@example.test', 'fixture', 'administrator', 1, NOW(), NOW())"
        );
        $statement = $pdo->prepare(
            'INSERT INTO staff_sessions '
            . '(id, staff_user_id, token_hash, created_at, last_seen_at, expires_at) '
            . 'VALUES (1, 1, :token_hash, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $statement->execute(['token_hash' => $staffTokenHash]);
        $pdo->exec(
            'INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) '
            . "VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'pending', NULL, 1, NOW(), NOW())"
        );

        $originalSession = $_SESSION ?? [];
        try {
            $_SESSION = [
                'staff_auth_token' => $staffToken,
                'parent_admin_preview' => $this->previewData($staffTokenHash),
            ];

            $sessions = new ParentSessionService($pdo, 1440, 60);
            $parent = $sessions->current();
            self::assertNotNull($parent);
            self::assertSame(1, $parent->id);
            self::assertSame('Erika Muster', $parent->displayName());
            self::assertTrue($parent->adminPreview);
            self::assertSame(1, $parent->previewStaffUserId);
            self::assertSame(0, $parent->sessionId);

            (new AuditLogger($pdo))->parent($parent, 'parent.preview.action', 'parent_contact', 1);
            $audit = $pdo->query(
                'SELECT actor_type, staff_user_id, parent_contact_id, metadata FROM audit_log '
                . "WHERE action = 'parent.preview.action' ORDER BY id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($audit);
            self::assertSame('staff', $audit['actor_type']);
            self::assertSame('1', (string) $audit['staff_user_id']);
            self::assertSame('1', (string) $audit['parent_contact_id']);
            self::assertStringContainsString('admin_parent_preview', (string) $audit['metadata']);

            $pdo->exec("UPDATE staff_users SET role = 'locker_manager' WHERE id = 1");
            $_SESSION['parent_admin_preview'] = $this->previewData($staffTokenHash);
            self::assertNull($sessions->current());
            self::assertArrayNotHasKey('parent_admin_preview', $_SESSION);

            $pdo->exec("UPDATE staff_users SET role = 'administrator' WHERE id = 1");
            $pdo->exec('UPDATE staff_sessions SET revoked_at = NOW() WHERE id = 1');
            $_SESSION['parent_admin_preview'] = $this->previewData($staffTokenHash);
            self::assertNull($sessions->current());
            self::assertArrayNotHasKey('parent_admin_preview', $_SESSION);
        } finally {
            $_SESSION = $originalSession;
        }
    }

    /** @return array{parent_contact_id:int,staff_user_id:int,staff_session_id:int,staff_token_hash:string,expires_at:int} */
    private function previewData(string $staffTokenHash): array
    {
        return [
            'parent_contact_id' => 1,
            'staff_user_id' => 1,
            'staff_session_id' => 1,
            'staff_token_hash' => $staffTokenHash,
            'expires_at' => time() + 1800,
        ];
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_parent_preview';

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

    private function resetFixtures(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE audit_log');
        $pdo->exec('TRUNCATE TABLE parent_sessions');
        $pdo->exec('TRUNCATE TABLE parent_contacts');
        $pdo->exec('TRUNCATE TABLE staff_sessions');
        $pdo->exec('TRUNCATE TABLE staff_users');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
