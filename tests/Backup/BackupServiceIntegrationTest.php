<?php

declare(strict_types=1);

namespace FachDock\Tests\Backup;

use FachDock\Backup\BackupService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupServiceIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $databases = [];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        $host = getenv('TEST_DB_HOST');
        if (is_string($host) && $host !== '') {
            $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
            $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
            $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
            $admin = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            foreach ($this->databases as $database) {
                $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
            }
        }

        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }
    }

    public function testFullRestoreReturnsDatabaseAndPersistentFilesToBackupState(): void
    {
        $pdo = $this->database();
        $root = $this->root();

        $pdo->exec(
            'CREATE TABLE parent_record ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'name VARCHAR(100) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $pdo->exec(
            'CREATE TABLE child_record ('
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'parent_id INT UNSIGNED NOT NULL,'
            . 'note VARCHAR(100) NOT NULL,'
            . 'CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent_record(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $pdo->exec("INSERT INTO parent_record (name) VALUES ('Vorher')");
        $pdo->exec("INSERT INTO child_record (parent_id, note) VALUES (1, 'Original')");

        file_put_contents($root . '/config/app.local.php', "<?php return ['test' => 'before'];\n");
        file_put_contents($root . '/config/secrets.local.php', "<?php return ['secret' => 'before'];\n");
        file_put_contents($root . '/storage/floorplans/plan.txt', 'before');

        $service = new BackupService($root, $pdo);
        $backup = $service->create();

        self::assertFileExists($backup['path']);
        self::assertFileExists($backup['path'] . '.sha256');
        self::assertSame(2, $backup['tables']);
        self::assertSame(2, $backup['rows']);
        self::assertSame(3, $backup['files']);

        $pdo->exec("UPDATE parent_record SET name = 'Nachher' WHERE id = 1");
        $pdo->exec('CREATE TABLE added_after_backup (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO added_after_backup (id) VALUES (1)');
        file_put_contents($root . '/config/app.local.php', "<?php return ['test' => 'after'];\n");
        file_put_contents($root . '/storage/floorplans/plan.txt', 'after');
        file_put_contents($root . '/storage/new-after-backup.txt', 'remove me');

        $restored = $service->restore($backup['path']);

        self::assertSame(2, $restored['tables']);
        self::assertSame(2, $restored['rows']);
        self::assertSame(3, $restored['files']);
        self::assertSame('Vorher', (string) $pdo->query('SELECT name FROM parent_record WHERE id = 1')->fetchColumn());
        self::assertSame('Original', (string) $pdo->query('SELECT note FROM child_record WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'added_after_backup'")->fetchColumn());
        self::assertSame("<?php return ['test' => 'before'];\n", file_get_contents($root . '/config/app.local.php'));
        self::assertSame('before', file_get_contents($root . '/storage/floorplans/plan.txt'));
        self::assertFileDoesNotExist($root . '/storage/new-after-backup.txt');
    }

    public function testRestoreRejectsBackupWithMismatchingChecksum(): void
    {
        $pdo = $this->database();
        $root = $this->root();
        $pdo->exec('CREATE TABLE sample (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO sample (id) VALUES (1)');

        $service = new BackupService($root, $pdo);
        $backup = $service->create();
        file_put_contents($backup['path'], 'tampered', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup-Prüfsumme stimmt nicht überein');
        $service->restore($backup['path']);
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
        $database = $prefix . '_backup_' . bin2hex(random_bytes(4));
        $this->databases[] = $database;

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/fachdock-backup-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        mkdir($root . '/config', 0770, true);
        mkdir($root . '/storage/floorplans', 0770, true);

        return $root;
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
