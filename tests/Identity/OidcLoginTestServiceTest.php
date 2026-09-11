<?php

declare(strict_types=1);

namespace FachDock\Tests\Identity;

use FachDock\Config\Config;
use FachDock\Identity\OidcConfiguration;
use FachDock\Identity\OidcHttpClient;
use FachDock\Identity\OidcLoginTestService;
use PHPUnit\Framework\TestCase;

final class OidcLoginTestServiceTest extends TestCase
{
    public function testLoginTestWorksBeforeProductionActivationAndReturnsUserinfoClaims(): void
    {
        $root = $this->configRoot();
        $originalSession = $_SESSION ?? [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        try {
            $_SESSION = [];
            $http = new LoginTestFakeOidcHttpClient();
            $service = new OidcLoginTestService(
                new OidcConfiguration(Config::load($root)),
                $http,
            );

            $authorization = $service->begin();
            parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);
            self::assertSame('S256', $query['code_challenge_method'] ?? null);
            self::assertSame(
                'openid profile email iserv:roles iserv:untis',
                $query['scope'] ?? null,
            );
            self::assertNotEmpty($query['state'] ?? null);
            self::assertTrue($service->pending());

            $http->userinfo = [
                'sub' => 'student-subject',
                'preferred_username' => 'ada.test',
                'untis_username' => '1001',
                'iserv:roles' => [['displayName' => 'Schüler']],
            ];
            $claims = $service->finish('test-code', (string) $query['state']);

            self::assertSame('1001', $claims['untis_username']);
            self::assertSame([['displayName' => 'Schüler']], $claims['iserv:roles']);
            self::assertFalse($service->pending());
            self::assertStringStartsWith('Basic ', $http->lastPostHeaders['Authorization'] ?? '');
            self::assertNotEmpty($http->lastPostFields['code_verifier'] ?? null);
        } finally {
            $_SESSION = $originalSession;
            $this->removeDirectory($root);
        }
    }

    private function configRoot(): string
    {
        $root = sys_get_temp_dir() . '/fachdock-oidc-login-test-' . bin2hex(random_bytes(5));
        mkdir($root . '/config', 0770, true);
        file_put_contents($root . '/config/app.php', <<<'PHP'
<?php
return [
    'app' => ['base_url' => 'https://fachdock.test'],
    'oidc' => [
        'enabled' => false,
        'issuer' => 'https://iserv.test',
        'client_id' => 'fachdock-client',
        'scopes' => 'openid profile email iserv:roles iserv:untis',
        'student_role_names' => 'Schüler',
        'student_match_claim' => 'untis_username',
        'student_match_field' => 'matrikelnummer',
        'teacher_role_names' => 'Lehrer',
    ],
];
PHP);
        file_put_contents($root . '/config/secrets.local.php', "<?php\nreturn ['oidc' => ['client_secret' => 'top-secret']];\n");

        return $root;
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

final class LoginTestFakeOidcHttpClient implements OidcHttpClient
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
