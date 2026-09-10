<?php

declare(strict_types=1);

namespace FachDock\Tests\Release;

use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class UpgradeFrom090IntegrationTest extends TestCase
{
    private ?string $databaseName = null;

    protected function tearDown(): void
    {
        if ($this->databaseName === null) {
            return;
        }
        $this->adminConnection()->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
        $this->databaseName = null;
    }

    public function testReleasedVersion090CanBeUpgradedWithoutLosingExistingData(): void
    {
        $v090Migrations = getenv('FACHDOCK_V090_MIGRATIONS');
        if (!is_string($v090Migrations) || !is_dir($v090Migrations)) {
            $this->markTestSkipped('FACHDOCK_V090_MIGRATIONS is not configured.');
        }

        $host = $this->requireDatabaseHost();
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $prefix = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test');
        $this->databaseName = $prefix . '_upgrade_090_' . bin2hex(random_bytes(4));

        $this->adminConnection()->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );

        $v090Runner = new MigrationRunner($pdo, $v090Migrations);
        $v090Count = $v090Runner->migrate();
        self::assertGreaterThan(0, $v090Count);
        self::assertSame(
            count(glob($v090Migrations . '/*.php') ?: []),
            (int) $pdo->query('SELECT COUNT(*) FROM sys_migrations')->fetchColumn(),
        );

        $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)'
        )->execute(['key' => 'release.upgrade.marker', 'value' => 'v0.9.0-data']);
        $pdo->exec(
            'INSERT INTO staff_users (username, display_name, email, password_hash, role, active, created_at, updated_at) '
            . "VALUES ('upgrade-admin', 'Upgrade Admin', 'upgrade@example.test', 'hash', 'administrator', 1, NOW(), NOW())"
        );

        self::assertSame(
            'NO',
            (string) $pdo->query(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() '
                . "AND table_name = 'privacy_anonymization_runs' AND column_name = 'staff_user_id'"
            )->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'oidc_identities'"
            )->fetchColumn(),
        );

        $currentRunner = new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations');
        $newMigrations = $currentRunner->migrate();
        self::assertGreaterThanOrEqual(2, $newMigrations);

        self::assertSame(
            'v0.9.0-data',
            (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'release.upgrade.marker'")->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM staff_users WHERE username = 'upgrade-admin' AND email = 'upgrade@example.test'")->fetchColumn(),
        );
        self::assertSame(
            'YES',
            (string) $pdo->query(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() '
                . "AND table_name = 'privacy_anonymization_runs' AND column_name = 'staff_user_id'"
            )->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'oidc_identities'"
            )->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'oidc_sessions'"
            )->fetchColumn(),
        );
        self::assertSame(
            count(glob(dirname(__DIR__, 2) . '/migrations/*.php') ?: []),
            (int) $pdo->query('SELECT COUNT(*) FROM sys_migrations')->fetchColumn(),
        );
        self::assertSame(0, $currentRunner->migrate(), 'The upgrade must be idempotent after it completed successfully.');
    }

    private function requireDatabaseHost(): string
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }

        return $host;
    }

    private function adminConnection(): PDO
    {
        $host = $this->requireDatabaseHost();
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
