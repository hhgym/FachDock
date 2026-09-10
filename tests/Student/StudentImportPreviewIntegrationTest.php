<?php

declare(strict_types=1);

namespace FachDock\Tests\Student;

use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Router;
use FachDock\Migration\MigrationRunner;
use FachDock\Security\Csrf;
use FachDock\Student\CsvStudentParser;
use FachDock\Student\StudentImportController;
use FachDock\Student\StudentImportProfileService;
use FachDock\Student\StudentImportService;
use FachDock\View\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class StudentImportPreviewIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $root;
    private Csrf $csrf;
    private Router $router;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/admin/students';

        $this->pdo = $this->database();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['student_import_runs', 'student_import_profiles', 'students', 'staff_sessions', 'staff_users'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->pdo->exec(
            "INSERT INTO staff_users "
            . "(username, display_name, email, password_hash, role, active, created_at, updated_at) VALUES "
            . "('admin', 'Administrator', 'admin@example.test', 'unused', 'administrator', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $staffUserId = (int) $this->pdo->lastInsertId();
        $token = 'student-import-preview-session';
        $statement = $this->pdo->prepare(
            'INSERT INTO staff_sessions '
            . '(staff_user_id, token_hash, created_at, last_seen_at, expires_at) '
            . 'VALUES (:staff_user_id, :token_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '
            . 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'token_hash' => hash('sha256', $token),
        ]);
        $_SESSION['staff_auth_token'] = $token;

        $this->root = sys_get_temp_dir() . '/fachdock-student-preview-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0770, true));

        $this->csrf = new Csrf();
        $this->router = new Router();
        $parser = new CsvStudentParser();
        (new StudentImportController(
            $this->root,
            $this->pdo,
            $parser,
            new StudentImportService($this->pdo),
            new StudentImportProfileService($this->pdo),
            new StaffSessionService($this->pdo),
            new ViewRenderer(dirname(__DIR__, 2) . '/templates'),
            $this->csrf,
        ))->register($this->router);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $_SESSION = [];
        unset($_SERVER['REQUEST_URI']);
    }

    public function testPreviewAutoDetectsCommaDelimiterEvenWhenSemicolonIsSelected(): void
    {
        $response = $this->preview(
            "Matrikelnummer,Vorname,Nachname,Klasse,Aktiv\n"
            . "1001,Anna,Muster,7-1,ja\n"
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Importvorschau', $response->body());
        self::assertStringContainsString('Anna Muster', $response->body());
        self::assertStringContainsString('7-1', $response->body());
    }

    public function testCsvValidationErrorStaysVisibleOnImportPageInsteadOfReturning422(): void
    {
        $response = $this->preview(
            "Matrikelnummer;Vorname;Nachname;Klasse\n"
            . "1002;Ben;Beispiel;8-2\n"
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Pflichtspalte fehlt oder ist nicht zugeordnet: active', $response->body());
        self::assertStringContainsString('CSV-Datei auswählen', $response->body());
    }

    private function preview(string $csv): \FachDock\Http\Response
    {
        $path = tempnam(sys_get_temp_dir(), 'fachdock-upload-');
        self::assertIsString($path);
        file_put_contents($path, $csv);
        $size = filesize($path);
        self::assertIsInt($size);

        $_SERVER['REQUEST_URI'] = '/admin/students/import/preview';
        try {
            return $this->router->dispatch(new Request(
                'POST',
                '/admin/students/import/preview',
                [],
                [
                    '_csrf' => $this->csrf->token(),
                    'profile_id' => '',
                    'delimiter' => ';',
                    'encoding' => 'UTF-8',
                ],
                [],
                [
                    'csv_file' => [
                        'name' => 'students.csv',
                        'tmp_name' => $path,
                        'size' => $size,
                        'error' => UPLOAD_ERR_OK,
                    ],
                ],
            ));
        } finally {
            @unlink($path);
        }
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_student_preview';
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

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
