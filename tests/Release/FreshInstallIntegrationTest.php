<?php

declare(strict_types=1);

namespace FachDock\Tests\Release;

use FachDock\Config\Config;
use FachDock\Installation\InstallerService;
use PDO;
use PHPUnit\Framework\TestCase;

final class FreshInstallIntegrationTest extends TestCase
{
    private ?string $databaseName = null;
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->databaseName !== null) {
            $admin = $this->adminConnection();
            $admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
            $this->databaseName = null;
        }

        if ($this->root !== null) {
            $this->removeDirectory($this->root);
            $this->root = null;
        }
    }

    public function testEmptySystemCanBeInstalledCompletely(): void
    {
        $host = $this->requireDatabaseHost();
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $prefix = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test');

        $this->databaseName = $prefix . '_fresh_install_' . bin2hex(random_bytes(4));
        $this->adminConnection()->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $this->root = sys_get_temp_dir() . '/fachdock-install-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0770, true);
        mkdir($this->root . '/storage', 0770, true);
        mkdir($this->root . '/migrations', 0770, true);

        $projectRoot = dirname(__DIR__, 2);
        copy($projectRoot . '/config/app.php', $this->root . '/config/app.php');
        foreach (glob($projectRoot . '/migrations/*.php') ?: [] as $migration) {
            copy($migration, $this->root . '/migrations/' . basename($migration));
        }

        (new InstallerService($this->root))->install([
            'school_name' => 'Heinrich-Hertz-Gymnasium Test',
            'admin_username' => 'admin',
            'admin_display_name' => 'Test Administrator',
            'admin_email' => 'admin@example.test',
            'admin_password' => 'VerySecure-Install-Password-2026',
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $this->databaseName,
            'db_username' => $username,
            'db_password' => $password,
        ]);

        self::assertFileExists($this->root . '/config/app.local.php');
        self::assertFileExists($this->root . '/config/secrets.local.php');

        $config = Config::load($this->root);
        self::assertTrue((bool) $config->get('app.installed'));
        self::assertSame('Heinrich-Hertz-Gymnasium Test', $config->get('app.school_name'));
        self::assertSame($this->databaseName, $config->get('database.name'));
        self::assertSame($password, $config->get('database.password'));

        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );

        $migrationFiles = glob($this->root . '/migrations/*.php') ?: [];
        self::assertNotEmpty($migrationFiles);
        self::assertSame(
            count($migrationFiles),
            (int) $pdo->query('SELECT COUNT(*) FROM sys_migrations')->fetchColumn(),
        );
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM staff_users WHERE username = 'admin' AND role = 'administrator' AND active = 1")->fetchColumn());
        self::assertSame(
            '"Heinrich-Hertz-Gymnasium Test"',
            (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'school.name'")->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.installation.completed'")->fetchColumn(),
        );
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'oidc_identities'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'privacy_anonymization_runs'")->fetchColumn());
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
