<?php

declare(strict_types=1);

namespace FachDock\Tests\Identity;

use FachDock\Config\Config;
use FachDock\Identity\OidcConfiguration;
use FachDock\Identity\OidcHttpClient;
use FachDock\Identity\OidcIdentityService;
use FachDock\Identity\OidcSessionService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class OidcIdentityIntegrationTest extends TestCase
{
    public function testPkceLoginMapsStudentTeacherAndLeavesUnknownIdentityPending(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec(
            "INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, email, active, created_at, updated_at) "
            . "VALUES (1, '1001', 'Ada', 'Test', '7-1', 7, 'ada@iserv.test', 1, NOW(), NOW())"
        );

        $root = $this->configRoot();
        $originalSession = $_SESSION ?? [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        try {
            $_SESSION = [];
            $config = Config::load($root);
            $http = new FakeOidcHttpClient();
            $service = new OidcIdentityService($pdo, new OidcConfiguration($config), $http);

            $authorization = $service->begin('student');
            parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
            self::assertSame('S256', $query['code_challenge_method'] ?? null);
            self::assertSame('openid profile email iserv:uuid iserv:groups iserv:roles', $query['scope'] ?? null);
            self::assertNotEmpty($query['code_challenge'] ?? null);
            self::assertNotEmpty($query['state'] ?? null);

            $http->userinfo = [
                'sub' => 'student-subject',
                'uuid' => 'student-uuid',
                'preferred_username' => 'ada.test',
                'name' => 'Ada Test',
                'email' => 'ada@iserv.test',
                'iserv:roles' => [],
            ];
            $studentResult = $service->finish('student-code', (string) $query['state']);
            self::assertSame('student', $studentResult['identity']['identity_type']);
            self::assertSame('1', (string) $studentResult['identity']['student_id']);
            self::assertSame('student', $studentResult['area']);
            self::assertStringStartsWith('Basic ', $http->lastPostHeaders['Authorization'] ?? '');
            self::assertNotEmpty($http->lastPostFields['code_verifier'] ?? null);

            $sessions = new OidcSessionService($pdo, 480, 60);
            $authenticated = $sessions->create((int) $studentResult['identity']['id'], '127.0.0.1', 'PHPUnit');
            self::assertTrue($authenticated->isStudent());
            self::assertSame(1, $authenticated->studentId);
            self::assertNotNull($sessions->current());
            $sessions->logout();

            $teacherAuthorization = $service->begin('teacher');
            parse_str((string) parse_url($teacherAuthorization, PHP_URL_QUERY), $teacherQuery);
            $http->userinfo = [
                'sub' => 'teacher-subject',
                'preferred_username' => 'lehrer.test',
                'name' => 'Lehrer Test',
                'email' => 'lehrer@iserv.test',
                'iserv:roles' => [['displayName' => 'Lehrer']],
            ];
            $teacherResult = $service->finish('teacher-code', (string) $teacherQuery['state']);
            self::assertSame('teacher', $teacherResult['identity']['identity_type']);
            self::assertNull($teacherResult['identity']['student_id']);

            $unknownAuthorization = $service->begin('student');
            parse_str((string) parse_url($unknownAuthorization, PHP_URL_QUERY), $unknownQuery);
            $http->userinfo = [
                'sub' => 'unknown-subject',
                'preferred_username' => 'unknown',
                'name' => 'Unknown User',
                'email' => 'unknown@iserv.test',
                'iserv:roles' => [],
            ];
            $unknownResult = $service->finish('unknown-code', (string) $unknownQuery['state']);
            self::assertSame('pending', $unknownResult['identity']['identity_type']);
            self::assertNull($unknownResult['identity']['student_id']);
            self::assertCount(3, $service->identities());
        } finally {
            $_SESSION = $originalSession;
            $this->removeDirectory($root);
        }
    }

    private function configRoot(): string
    {
        $root = sys_get_temp_dir() . '/fachdock-oidc-' . bin2hex(random_bytes(5));
        mkdir($root . '/config', 0770, true);
        file_put_contents($root . '/config/app.php', <<<'PHP'
<?php
return [
    'app' => ['base_url' => 'https://fachdock.test'],
    'oidc' => [
        'enabled' => true,
        'issuer' => 'https://iserv.test',
        'client_id' => 'fachdock-client',
        'scopes' => 'openid profile email iserv:uuid iserv:groups iserv:roles',
        'student_auto_match' => 'email',
        'teacher_role_names' => 'Lehrer, Lehrkräfte',
        'session_lifetime_minutes' => 480,
    ],
];
PHP);
        file_put_contents($root . '/config/secrets.local.php', "<?php\nreturn ['oidc' => ['client_secret' => 'top-secret']];\n");

        return $root;
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_oidc';
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }
}

final class FakeOidcHttpClient implements OidcHttpClient
{
    /** @var array<string, mixed> */
    public array $userinfo = [];
    /** @var array<string, string> */
    public array $lastPostFields = [];
    /** @var array<string, string> */
    public array $lastPostHeaders = [];

    public function getJson(string $url, array $headers = []): array
    {
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return [
                'issuer' => 'https://iserv.test',
                'authorization_endpoint' => 'https://iserv.test/iserv/auth/auth',
                'token_endpoint' => 'https://iserv.test/iserv/auth/public/token',
                'userinfo_endpoint' => 'https://iserv.test/iserv/auth/userinfo',
            ];
        }
        if ($url === 'https://iserv.test/iserv/auth/userinfo') {
            return $this->userinfo;
        }

        return [];
    }

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        $this->lastPostFields = $fields;
        $this->lastPostHeaders = $headers;

        return ['access_token' => 'access-token', 'token_type' => 'Bearer'];
    }
}
