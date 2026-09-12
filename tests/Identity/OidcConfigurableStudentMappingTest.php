<?php

declare(strict_types=1);

namespace FachDock\Tests\Identity;

use FachDock\Config\Config;
use FachDock\Identity\OidcConfiguration;
use FachDock\Identity\OidcHttpClient;
use FachDock\Identity\OidcIdentityService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class OidcConfigurableStudentMappingTest extends TestCase
{
    public function testUntisUsernameCanMapToMatrikelnummerWhenStudentRoleMatches(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec(
            'INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, email, active, created_at, updated_at) '
            . "VALUES (1, '1001', 'Ada', 'Test', '7-1', 7, 'ada@school.test', 1, NOW(), NOW())"
        );
        $pdo->exec(
            'INSERT INTO students '
            . '(id, matrikelnummer, first_name, last_name, class_name, grade, email, active, inactive_since, created_at, updated_at) '
            . "VALUES (2, '1002', 'Grace', 'Abroad', '8-1', 8, 'grace@school.test', 0, NOW(), NOW(), NOW())"
        );

        $root = $this->configRoot();
        $originalSession = $_SESSION ?? [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        try {
            $_SESSION = [];
            $http = new ConfigurableMappingFakeOidcHttpClient();
            $service = new OidcIdentityService(
                $pdo,
                new OidcConfiguration(Config::load($root)),
                $http,
            );

            $http->userinfo = [
                'sub' => 'student-subject',
                'preferred_username' => 'ada.test',
                'email' => 'different@school.test',
                'untis_username' => '1001',
                'iserv:roles' => [['displayName' => 'Schüler']],
            ];
            $authorization = $service->begin('student');
            parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
            self::assertStringContainsString('iserv:untis', (string) ($query['scope'] ?? ''));

            $result = $service->finish('student-code', (string) $query['state']);
            self::assertSame('student', $result['identity']['identity_type']);
            self::assertSame('1', (string) $result['identity']['student_id']);
            self::assertSame('ada.test', $result['identity']['account_name']);
            self::assertSame('different@school.test', $result['identity']['email']);

            $http->userinfo = [
                'sub' => 'student-in-grace-period',
                'preferred_username' => 'grace.abroad',
                'email' => 'grace@school.test',
                'untis_username' => '1002',
                'iserv:roles' => [['displayName' => 'Schüler']],
            ];
            $authorization = $service->begin('student');
            parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
            $gracePeriod = $service->finish('grace-code', (string) $query['state']);
            self::assertSame('student', $gracePeriod['identity']['identity_type']);
            self::assertSame('2', (string) $gracePeriod['identity']['student_id']);

            $http->userinfo = [
                'sub' => 'no-student-role',
                'preferred_username' => 'other.test',
                'untis_username' => '1001',
                'iserv:roles' => [['displayName' => 'Gast']],
            ];
            $authorization = $service->begin('student');
            parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
            $withoutRole = $service->finish('other-code', (string) $query['state']);
            self::assertSame('pending', $withoutRole['identity']['identity_type']);
            self::assertNull($withoutRole['identity']['student_id']);
        } finally {
            $_SESSION = $originalSession;
            $this->removeDirectory($root);
        }
    }

    private function configRoot(): string
    {
        $root = sys_get_temp_dir() . '/fachdock-oidc-configurable-' . bin2hex(random_bytes(5));
        mkdir($root . '/config', 0770, true);
        file_put_contents($root . '/config/app.php', <<<'PHP'
<?php
return [
    'app' => ['base_url' => 'https://fachdock.test'],
    'oidc' => [
        'enabled' => true,
        'issuer' => 'https://iserv.test',
        'client_id' => 'fachdock-client',
        'scopes' => 'openid profile email iserv:roles iserv:untis',
        'student_role_names' => 'Schüler',
        'student_match_claim' => 'untis_username',
        'student_match_field' => 'matrikelnummer',
        'teacher_role_names' => 'Lehrer',
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_oidc_configurable';
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

final class ConfigurableMappingFakeOidcHttpClient implements OidcHttpClient
{
    /** @var array<string, mixed> */
    public array $userinfo = [];

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
        return ['access_token' => 'access-token', 'token_type' => 'Bearer'];
    }
}
