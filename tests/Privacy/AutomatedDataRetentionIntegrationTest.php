<?php

declare(strict_types=1);

namespace FachDock\Tests\Privacy;

use DateTimeImmutable;
use FachDock\Migration\MigrationRunner;
use FachDock\Privacy\DataRetentionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutomatedDataRetentionIntegrationTest extends TestCase
{
    private ?string $databaseName = null;

    protected function tearDown(): void
    {
        if ($this->databaseName === null) {
            return;
        }
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            return;
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
    }

    public function testAutomatedRunNeedsNoStaffActorAndAppearsInHistory(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $service = new DataRetentionService($pdo);
        $summary = $service->anonymize(null, new DateTimeImmutable('2026-09-10'));

        self::assertSame([
            'students' => 0,
            'parents' => 0,
            'mails' => 0,
            'mail_history' => 0,
            'audit_entries' => 0,
            'incidents' => 0,
        ], $summary);

        $runs = $service->recentRuns(1);
        self::assertCount(1, $runs);
        self::assertNull($runs[0]['staff_user_id']);
        self::assertNull($runs[0]['staff_name']);
        self::assertSame('completed', $runs[0]['status']);
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
        $prefix = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test');
        $this->databaseName = $prefix . '_privacy_auto_' . bin2hex(random_bytes(4));

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
