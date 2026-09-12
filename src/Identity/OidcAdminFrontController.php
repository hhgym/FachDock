<?php

declare(strict_types=1);

namespace FachDock\Identity;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Config\LocalConfigWriter;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use RuntimeException;
use Throwable;

final class OidcAdminFrontController
{
    private const LOGIN_TEST_REDIRECT_KEY = 'oidc_admin_login_test_redirect';

    /** @var list<string> */
    private const PATHS = [
        '/admin/config/oidc',
        '/admin/config/oidc/test',
        '/admin/config/oidc/login-test',
        '/admin/config/oidc/identity',
        '/sso/callback',
    ];

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if (!in_array($path, self::PATHS, true)) {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        if (is_file($root . '/storage/maintenance.flag')) {
            return Response::html('<h1>FachDock wird aktualisiert.</h1><p>Bitte laden Sie die Seite in Kürze erneut.</p>', 503);
        }

        self::startSession();
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $views = new ViewRenderer((string) $config->get('paths.templates', $root . '/templates'));
        $csrf = new Csrf();
        $request = Request::fromGlobals();
        $configuration = new OidcConfiguration($config);
        $http = new CurlOidcHttpClient();
        $identityService = new OidcIdentityService($pdo, $configuration, $http);
        $loginTest = new OidcLoginTestService($configuration, $http);
        $staffSessions = new StaffSessionService(
            $pdo,
            self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $audit = new AuditLogger($pdo);
        $logger = LoggerFactory::create($root);
        $testCallback = $path === '/sso/callback' && $loginTest->pending();

        if ($path === '/sso/callback' && !$testCallback) {
            return null;
        }

        try {
            $staff = self::administrator($staffSessions);
            if ($staff instanceof Response) {
                return $staff;
            }

            if ($testCallback && $request->method() === 'GET') {
                $claims = $loginTest->finish(
                    self::queryString($request, 'code'),
                    self::queryString($request, 'state'),
                    self::queryString($request, 'error'),
                );
                $audit->staff($staff, 'system.oidc.login_tested', 'system', 'oidc', [
                    'claim_count' => count($claims),
                    'claim_names' => array_keys($claims),
                ]);

                return self::adminPage(
                    $views,
                    $csrf,
                    $staff,
                    $config,
                    $configuration,
                    $identityService,
                    [],
                    false,
                    null,
                    $claims,
                );
            }

            if ($path === '/admin/config/oidc' && $request->method() === 'GET') {
                return self::adminPage(
                    $views,
                    $csrf,
                    $staff,
                    $config,
                    $configuration,
                    $identityService,
                    [],
                    self::queryString($request, 'saved') === '1',
                );
            }

            if ($path === '/admin/config/oidc' && $request->method() === 'POST') {
                return self::saveSettings(
                    $request,
                    $root,
                    $config,
                    $views,
                    $csrf,
                    $staff,
                    $configuration,
                    $identityService,
                    $audit,
                );
            }

            if ($path === '/admin/config/oidc/test' && $request->method() === 'POST') {
                self::assertCsrf($request, $csrf);
                $test = $loginTest->connectionCheck();
                $audit->staff($staff, 'system.oidc.discovery_tested', 'system', 'oidc');

                return self::adminPage(
                    $views,
                    $csrf,
                    $staff,
                    $config,
                    $configuration,
                    $identityService,
                    [],
                    false,
                    $test,
                );
            }

            if ($path === '/admin/config/oidc/login-test'
                && $request->method() === 'GET'
                && self::queryString($request, 'continue') === '1') {
                return Response::redirect(self::consumeLoginTestRedirect());
            }

            if ($path === '/admin/config/oidc/login-test' && $request->method() === 'POST') {
                self::assertCsrf($request, $csrf);
                self::storeLoginTestRedirect($loginTest->begin());

                return self::loginTestHandoffPage();
            }

            if ($path === '/admin/config/oidc/identity' && $request->method() === 'POST') {
                return self::mapIdentity(
                    $request,
                    $csrf,
                    $staff,
                    $identityService,
                    $audit,
                );
            }

            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        } catch (RuntimeException $exception) {
            $staff = self::administrator($staffSessions);
            if ($staff instanceof Response) {
                return $staff;
            }

            return self::adminPage(
                $views,
                $csrf,
                $staff,
                $config,
                $configuration,
                $identityService,
                [$exception->getMessage()],
                false,
                null,
                null,
                422,
            );
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $logger->error('OIDC administration failed', ['error_id' => $errorId, 'exception' => $exception]);

            return Response::html(
                '<h1>Technischer Fehler</h1><p>Die OpenID-Connect-Konfiguration konnte nicht verarbeitet werden. Fehler-ID: '
                . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</p>',
                500,
            );
        }
    }

    private static function saveSettings(
        Request $request,
        string $root,
        Config $config,
        ViewRenderer $views,
        Csrf $csrf,
        AuthenticatedStaff $staff,
        OidcConfiguration $configuration,
        OidcIdentityService $identityService,
        AuditLogger $audit,
    ): Response {
        self::assertCsrf($request, $csrf);

        $enabled = $request->postString('enabled') === '1';
        $issuer = rtrim(trim($request->postString('issuer')), '/');
        $clientId = trim($request->postString('client_id'));
        $secret = trim($request->postString('client_secret'));
        $loginLabel = trim($request->postString('login_label'));
        $scopes = preg_replace('/\s+/', ' ', trim($request->postString('scopes'))) ?: '';
        $studentRoles = trim($request->postString('student_role_names'));
        $studentMatchClaim = trim($request->postString('student_match_claim'));
        $studentMatchField = $request->postString('student_match_field');
        $teacherRoles = trim($request->postString('teacher_role_names'));
        $sessionLifetime = self::intRange(
            $request->postString('session_lifetime_minutes'),
            15,
            10080,
            'Sitzungsdauer',
        );

        if ($loginLabel === '') {
            $loginLabel = 'Mit OpenID Connect anmelden';
        }
        if (mb_strlen($loginLabel) > 80) {
            throw new RuntimeException('Der Text für die OpenID-Connect-Anmeldung darf höchstens 80 Zeichen lang sein.');
        }
        if (!in_array($studentMatchField, ['none', 'email', 'matrikelnummer'], true)) {
            throw new RuntimeException('Das FachDock-Zielfeld für die Schülerzuordnung ist ungültig.');
        }
        if ($studentMatchField !== 'none'
            && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $studentMatchClaim) !== 1) {
            throw new RuntimeException('Der OpenID-Connect-Claim für die Schülerzuordnung ist ungültig.');
        }
        if ($enabled) {
            if (filter_var($issuer, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($issuer), 'https://')) {
                throw new RuntimeException('Der OpenID-Connect-Issuer muss eine gültige HTTPS-URL sein.');
            }
            if ($clientId === '') {
                throw new RuntimeException('Die Client-ID darf bei aktivierter OpenID-Connect-Anmeldung nicht leer sein.');
            }
            if ($secret === '' && $configuration->clientSecret() === '') {
                throw new RuntimeException('Das Client-Geheimnis darf bei aktivierter OpenID-Connect-Anmeldung nicht leer sein.');
            }
            if (!in_array('openid', preg_split('/\s+/', $scopes) ?: [], true)) {
                throw new RuntimeException('Die OpenID-Connect-Scopes müssen mindestens openid enthalten.');
            }
            $baseUrl = trim((string) $config->get('app.base_url', ''));
            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                throw new RuntimeException(
                    'Vor Aktivierung von OpenID Connect muss unter Allgemein eine öffentliche HTTPS-Basis-URL gesetzt sein.',
                );
            }
        }

        $legacyAutoMatch = match (true) {
            $studentMatchField === 'email' && $studentMatchClaim === 'email' => 'email',
            $studentMatchField === 'matrikelnummer' && $studentMatchClaim === 'preferred_username' => 'username_to_matrikelnummer',
            default => 'none',
        };
        $settings = [
            'enabled' => $enabled,
            'issuer' => $issuer,
            'client_id' => $clientId,
            'login_label' => $loginLabel,
            'scopes' => $scopes !== ''
                ? $scopes
                : 'openid profile email iserv:uuid iserv:groups iserv:roles iserv:untis',
            'student_auto_match' => $legacyAutoMatch,
            'student_role_names' => $studentRoles,
            'student_match_claim' => $studentMatchClaim,
            'student_match_field' => $studentMatchField,
            'teacher_role_names' => $teacherRoles,
            'session_lifetime_minutes' => $sessionLifetime,
        ];

        (new LocalConfigWriter($root))->saveOidcSettings($settings, $secret !== '' ? $secret : null);
        $audit->staff($staff, 'system.oidc.settings.updated', 'system', 'oidc', [
            'enabled' => $enabled,
            'issuer' => $issuer,
            'client_id' => $clientId,
            'login_label' => $loginLabel,
            'scopes' => $settings['scopes'],
            'student_role_names' => $studentRoles,
            'student_match_claim' => $studentMatchClaim,
            'student_match_field' => $studentMatchField,
            'teacher_role_names' => $teacherRoles,
            'session_lifetime_minutes' => $sessionLifetime,
            'client_secret_replaced' => $secret !== '',
        ]);
        $csrf->rotate();

        return Response::redirect('/admin/config/oidc?saved=1');
    }

    private static function mapIdentity(
        Request $request,
        Csrf $csrf,
        AuthenticatedStaff $staff,
        OidcIdentityService $identityService,
        AuditLogger $audit,
    ): Response {
        self::assertCsrf($request, $csrf);
        $id = self::intRange($request->postString('identity_id'), 1, PHP_INT_MAX, 'Identität');
        $action = $request->postString('action');
        $metadata = ['action' => $action];

        if ($action === 'teacher') {
            $identityService->approveTeacher($id);
        } elseif ($action === 'student') {
            $studentId = $identityService->linkStudent($id, $request->postString('matrikelnummer'));
            $metadata['student_id'] = $studentId;
        } elseif ($action === 'pending') {
            $identityService->setPending($id);
        } elseif ($action === 'automatic') {
            $identityService->useAutomaticAssignment($id);
        } elseif ($action === 'enable') {
            $identityService->setActive($id, true);
        } elseif ($action === 'disable') {
            $identityService->setActive($id, false);
        } else {
            throw new RuntimeException('Die gewünschte Identitätsaktion ist ungültig.');
        }

        $audit->staff($staff, 'system.oidc.identity.updated', 'oidc_identity', $id, $metadata);
        $csrf->rotate();

        return Response::redirect('/admin/config/oidc');
    }

    private static function loginTestHandoffPage(): Response
    {
        $continueUrl = '/admin/config/oidc/login-test?continue=1';

        return Response::html(
            '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="0;url=' . $continueUrl . '">'
            . '<title>OpenID Connect · FachDock</title></head><body>'
            . '<main><h1>OpenID-Connect-Testanmeldung</h1>'
            . '<p>Die Anmeldung wird gestartet.</p>'
            . '<p><a href="' . $continueUrl . '">Weiter zur Anmeldung</a></p>'
            . '</main></body></html>',
        );
    }

    /**
     * @param list<string> $errors
     * @param array<string, string>|null $testResult
     * @param array<string, mixed>|null $loginTestResult
     */
    private static function adminPage(
        ViewRenderer $views,
        Csrf $csrf,
        AuthenticatedStaff $staff,
        Config $config,
        OidcConfiguration $configuration,
        OidcIdentityService $identityService,
        array $errors = [],
        bool $saved = false,
        ?array $testResult = null,
        ?array $loginTestResult = null,
        int $status = 200,
    ): Response {
        return Response::html($views->render('config-oidc.php', [
            'staff' => $staff,
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
            'saved' => $saved,
            'testResult' => $testResult,
            'loginTestResult' => $loginTestResult,
            'settings' => [
                'enabled' => $configuration->enabled(),
                'issuer' => $configuration->issuer(),
                'client_id' => $configuration->clientId(),
                'secret_present' => $configuration->clientSecret() !== '',
                'login_label' => $configuration->loginLabel(),
                'scopes' => $configuration->scopes(),
                'student_role_names' => (string) $config->get('oidc.student_role_names', ''),
                'student_match_claim' => $configuration->studentMatchClaim(),
                'student_match_field' => $configuration->studentMatchField(),
                'teacher_role_names' => (string) $config->get('oidc.teacher_role_names', 'Lehrer, Lehrkräfte'),
                'session_lifetime_minutes' => $configuration->sessionLifetimeMinutes(),
                'callback_url' => $configuration->callbackUrl(),
            ],
            'identities' => $identityService->identities(),
        ]), $status);
    }

    private static function administrator(StaffSessionService $sessions): AuthenticatedStaff|Response
    {
        $staff = $sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        return $staff;
    }

    private static function assertCsrf(Request $request, Csrf $csrf): void
    {
        if (!$csrf->verify($request->postString('_csrf'))) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte erneut versuchen.');
        }
    }

    private static function storeLoginTestRedirect(string $url): void
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Die OpenID-Connect-Testanmeldung enthält keine sichere Weiterleitungsadresse.');
        }
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('Die Sitzung für die OpenID-Connect-Testanmeldung konnte nicht fortgesetzt werden.');
        }
        $_SESSION[self::LOGIN_TEST_REDIRECT_KEY] = $url;
        session_write_close();
    }

    private static function consumeLoginTestRedirect(): string
    {
        $url = $_SESSION[self::LOGIN_TEST_REDIRECT_KEY] ?? null;
        unset($_SESSION[self::LOGIN_TEST_REDIRECT_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (!is_string($url) || !str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Die OpenID-Connect-Testanmeldung ist abgelaufen. Bitte erneut starten.');
        }

        return $url;
    }

    private static function queryString(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    private static function intRange(string $value, int $minimum, int $maximum, string $label): int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        if ($filtered === false || $filtered < $minimum || $filtered > $maximum) {
            throw new RuntimeException($label . ' ist ungültig.');
        }

        return $filtered;
    }

    private static function configInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('fachdock');
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
