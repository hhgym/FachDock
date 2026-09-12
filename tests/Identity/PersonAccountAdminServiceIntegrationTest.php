<?php

declare(strict_types=1);

namespace FachDock\Tests\Identity;

use FachDock\Identity\PersonAccountAdminService;
use FachDock\Migration\MigrationRunner;
use FachDock\Privacy\AccountLifecycleService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PersonAccountAdminServiceIntegrationTest extends TestCase
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
        $this->databaseName = $prefix . '_person_admin_' . bin2hex(random_bytes(4));
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
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

    public function testSearchFiltersWorkWithNativePreparedStatements(): void
    {
        $service = new PersonAccountAdminService(
            $this->pdo(),
            new AccountLifecycleService($this->pdo()),
        );

        $studentData = $service->studentData('8-1');
        $studentAccounts = $service->studentAccounts('8-1');
        $parentAccounts = $service->parentAccounts('muster');

        self::assertCount(1, $studentData);
        self::assertSame('8-1', $studentData[0]['class_name']);
        self::assertCount(1, $studentAccounts);
        self::assertSame('1001', $studentAccounts[0]['matrikelnummer']);
        self::assertCount(1, $parentAccounts);
        self::assertSame('parent@example.test', $parentAccounts[0]['email']);
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    private function seed(): void
    {
        $this->pdo()->exec(
            "INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, email, active, access_code_hash, created_at, updated_at) "
            . "VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 'max@example.test', 1, 'test-hash', NOW(), NOW())"
        );
        $this->pdo()->exec(
            "INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) "
            . "VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())"
        );
        $this->pdo()->exec(
            "INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) "
            . "VALUES (1, 1, 1, 'staff', NOW(), NOW())"
        );
        $this->pdo()->exec(
            'INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())'
        );
    }
}
