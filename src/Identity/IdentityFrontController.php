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
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Operations\LockerSupportNotificationService;
use FachDock\Operations\LockerSupportService;
use FachDock\Operations\StudentSupportSessionService;
use FachDock\Security\Csrf;
use FachDock\Student\AccessCodeGenerator;
use FachDock\View\ViewRenderer;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class IdentityFrontController
{
    /** @var list<string> */
    private const PATHS = [
        '/sso/login',
        '/sso/callback',
        '/sso/logout',
        '/teacher',
        '/admin/config/oidc',
        '/admin/config/oidc/test',
        '/admin/config/oidc/identity',
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
        $logger = LoggerFactory::create($root);
        $views = new ViewRenderer((string) $config->get('paths.templates', $root . '/templates'));
        $csrf = new Csrf();
        $request = Request::fromGlobals();
        $configuration = new OidcConfiguration($config);
        $identityService = new OidcIdentityService($pdo, $configuration, new CurlOidcHttpClient());
        $oidcSessions = new OidcSessionService($pdo, $configuration->sessionLifetimeMinutes());
        $staffSessions = new StaffSessionService(
            $pdo,
            self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $audit = new AuditLogger($pdo);

        try {
            if ($path === '/sso/login' && $request->method() === 'GET') {
                $area = self::queryString($request, 'area', 'student');

                return Response::redirect($identityService->begin($area));
            }
            if ($path === '/sso/callback' && $request->method() === 'GET') {
                return self::callback(
                    $request,
                    $pdo,
                    $config,
                    $identityService,
                    $oidcSessions,
                    $logger,
                    $views,
                );
            }
            if ($path === '/sso/logout' && $request->method() === 'POST') {
                if (!$csrf->verify($request->postString('_csrf'))) {
                    return Response::html('<h1>Ungültige Sitzung</h1>', 419);
                }
                $oidcSessions->logout();
                unset($_SESSION['student_support_session']);
                $csrf->rotate();

                return Response::redirect('/login');
            }
            if ($path === '/teacher' && $request->method() === 'GET') {
                $identity = $oidcSessions->current();
                if ($identity === null || !$identity->isTeacher()) {
                    return Response::redirect('/sso/login?area=teacher');
                }
                $query = self::queryString($request, 'q');

                return Response::html($views->render('teacher-portal.php', [
                    'identity' => $identity,
                    'csrfToken' => $csrf->token(),
                    'query' => $query,
                    'assignments' => (new TeacherReadService($pdo))->assignments($query),
                ]));
            }

            $staff = self::administrator($staffSessions);
            if ($staff instanceof Response) {
                return $staff;
            }
            if ($path === '/admin/config/oidc' && $request->method() === 'GET') {
                return self::adminPage(
                    $views,
                    $csrf,
                    $staff,
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
                if (!$csrf->verify($request->postString('_csrf'))) {
                    return self::adminPage(
                        $views,
                        $csrf,
                        $staff,
                        $configuration,
                        $identityService,
                        ['Die Sitzung ist abgelaufen.'],
                        false,
                        null,
                        419,
                    );
                }
                $test = $identityService->connectionCheck();
                $audit->staff($staff, 'system.oidc.discovery_tested', 'system', 'oidc');

                return self::adminPage($views, $csrf, $staff, $configuration, $identityService, [], false, $test);
            }
            if ($path === '/admin/config/oidc/identity' && $request->method() === 'POST') {
                return self::mapIdentity(
                    $request,
                    $views,
                    $csrf,
                    $staff,
                    $configuration,
                    $identityService,
                    $audit,
                );
            }

            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        } catch (RuntimeException $exception) {
            if (str_starts_with($path, '/admin/config/oidc')) {
                $staff = self::administrator($staffSessions);
                if ($staff instanceof Response) {
                    return $staff;
                }

                return self::adminPage(
                    $views,
                    $csrf,
                    $staff,
                    $configuration,
                    $identityService,
                    [$exception->getMessage()],
                    false,
                    null,
                    422,
                );
            }

            return Response::html($views->render('oidc-error.php', ['message' => $exception->getMessage()]), 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $logger->error('OIDC integration failed', ['error_id' => $errorId, 'exception' => $exception]);

            return Response::html($views->render('oidc-error.php', [
                'message' => 'Technischer Fehler bei der IServ-Anmeldung. Fehler-ID: ' . $errorId,
            ]), 500);
        }
    }

    private static function callback(
        Request $request,
        PDO $pdo,
        Config $config,
        OidcIdentityService $identityService,
        OidcSessionService $oidcSessions,
        LoggerInterface $logger,
        ViewRenderer $views,
    ): Response {
        $providerError = self::queryString($request, 'error');
        if ($providerError !== '') {
            throw new RuntimeException('IServ hat die Anmeldung abgebrochen: ' . $providerError);
        }
        $result = $identityService->finish(
            self::queryString($request, 'code'),
            self::queryString($request, 'state'),
        );
        $identity = $result['identity'];
        if (empty($identity['active'])) {
            throw new RuntimeException('Dieses IServ-Konto ist in FachDock deaktiviert.');
        }
        $type = (string) $identity['identity_type'];
        if ($type === 'pending') {
            throw new RuntimeException(
                'Das IServ-Konto ist noch keinem Schüler oder Lehrkräftezugang zugeordnet. Bitte an die Administration wenden.',
            );
        }
        $authenticated = $oidcSessions->create((int) $identity['id'], $request->clientIp(), $request->userAgent());
        if ($authenticated->isStudent()) {
            $mailQueue = new MailQueueService($pdo, new MailTemplateRenderer());
            $notifications = new LockerSupportNotificationService(
                $mailQueue,
                (string) $config->get('app.base_url', ''),
                (string) $config->get('app.school_name', ''),
                $logger,
            );
            $support = new LockerSupportService($pdo, $notifications);
            (new StudentSupportSessionService($support, new AccessCodeGenerator()))->createForStudentId(
                (int) $authenticated->studentId,
            );

            return Response::redirect('/student/support');
        }
        if ($authenticated->isTeacher()) {
            return Response::redirect('/teacher');
        }

        return Response::html($views->render('oidc-error.php', [
            'message' => 'Die IServ-Identität hat keine zulässige Rolle.',
        ]), 403);
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
        if (!$csrf->verify($request->postString('_csrf'))) {
            return self::adminPage(
                $views,
                $csrf,
                $staff,
                $configuration,
                $identityService,
                ['Die Sitzung ist abgelaufen.'],
                false,
                null,
                419,
            );
        }
        $enabled = $request->postString('enabled') === '1';
        $issuer = rtrim(trim($request->postString('issuer')), '/');
        $clientId = trim($request->postString('client_id'));
        $scopes = preg_replace('/\s+/', ' ', trim($request->postString('scopes'))) ?: '';
        $autoMatch = $request->postString('student_auto_match');
        $teacherRoles = trim($request->postString('teacher_role_names'));
        $secret = trim($request->postString('client_secret'));
        $sessionLifetime = self::intRange(
            $request->postString('session_lifetime_minutes'),
            15,
            10080,
            'Sitzungsdauer',
        );
        if (!in_array($autoMatch, ['none', 'email', 'username_to_matrikelnummer'], true)) {
            throw new RuntimeException('Die automatische Schülerzuordnung ist ungültig.');
        }
        if ($enabled) {
            if (filter_var($issuer, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($issuer), 'https://')) {
                throw new RuntimeException('Der IServ-Issuer muss eine gültige HTTPS-URL sein.');
            }
            if ($clientId === '') {
                throw new RuntimeException('Die Client-ID darf bei aktivierter IServ-Anmeldung nicht leer sein.');
            }
            if ($secret === '' && $configuration->clientSecret() === '') {
                throw new RuntimeException('Das Client-Geheimnis darf bei aktivierter IServ-Anmeldung nicht leer sein.');
            }
            if (!in_array('openid', preg_split('/\s+/', $scopes) ?: [], true)) {
                throw new RuntimeException('Die OIDC-Scopes müssen mindestens openid enthalten.');
            }
            $baseUrl = trim((string) $config->get('app.base_url', ''));
            if (!str_starts_with(strtolower($baseUrl), 'https://')) {
                throw new RuntimeException(
                    'Vor Aktivierung von OIDC muss unter Allgemein eine öffentliche HTTPS-Basis-URL gesetzt sein.',
                );
            }
        }
        $settings = [
            'enabled' => $enabled,
            'issuer' => $issuer,
            'client_id' => $clientId,
            'scopes' => $scopes !== '' ? $scopes : 'openid profile email iserv:uuid iserv:groups iserv:roles',
            'student_auto_match' => $autoMatch,
            'teacher_role_names' => $teacherRoles,
            'session_lifetime_minutes' => $sessionLifetime,
        ];
        (new LocalConfigWriter($root))->saveOidcSettings($settings, $secret !== '' ? $secret : null);
        $audit->staff($staff, 'system.oidc.settings.updated', 'system', 'oidc', [
            'enabled' => $enabled,
            'issuer' => $issuer,
            'client_id' => $clientId,
            'scopes' => $settings['scopes'],
            'student_auto_match' => $autoMatch,
            'teacher_role_names' => $teacherRoles,
            'session_lifetime_minutes' => $sessionLifetime,
            'client_secret_replaced' => $secret !== '',
        ]);
        $csrf->rotate();

        return Response::redirect('/admin/config/oidc?saved=1');
    }

    private static function mapIdentity(
        Request $request,
        ViewRenderer $views,
        Csrf $csrf,
        AuthenticatedStaff $staff,
        OidcConfiguration $configuration,
        OidcIdentityService $identityService,
        AuditLogger $audit,
    ): Response {
        if (!$csrf->verify($request->postString('_csrf'))) {
            return self::adminPage(
                $views,
                $csrf,
                $staff,
                $configuration,
                $identityService,
                ['Die Sitzung ist abgelaufen.'],
                false,
                null,
                419,
            );
        }
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

    /** @param list<string> $errors
     *  @param array<string, string>|null $testResult
     */
    private static function adminPage(
        ViewRenderer $views,
        Csrf $csrf,
        AuthenticatedStaff $staff,
        OidcConfiguration $configuration,
        OidcIdentityService $identityService,
        array $errors = [],
        bool $saved = false,
        ?array $testResult = null,
        int $status = 200,
    ): Response {
        return Response::html($views->render('config-oidc.php', [
            'staff' => $staff,
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
            'saved' => $saved,
            'testResult' => $testResult,
            'identities' => $identityService->identities(),
            'settings' => [
                'enabled' => $configuration->enabled(),
                'issuer' => $configuration->issuer(),
                'client_id' => $configuration->clientId(),
                'secret_present' => $configuration->clientSecret() !== '',
                'scopes' => $configuration->scopes(),
                'student_auto_match' => $configuration->studentAutoMatch(),
                'teacher_role_names' => implode(', ', $configuration->teacherRoleNames()),
                'session_lifetime_minutes' => $configuration->sessionLifetimeMinutes(),
                'callback_url' => $configuration->callbackUrl(),
            ],
        ]), $status);
    }

    private static function administrator(StaffSessionService $sessions): AuthenticatedStaff|Response
    {
        $staff = $sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>403</h1><p>Nur Administratoren dürfen die OIDC-Konfiguration ändern.</p>', 403);
        }

        return $staff;
    }

    private static function queryString(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private static function intRange(string $value, int $min, int $max, string $label): int
    {
        if (!ctype_digit($value)) {
            throw new RuntimeException($label . ' ist ungültig.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new RuntimeException($label . ' ist außerhalb des zulässigen Bereichs.');
        }

        return $number;
    }

    private static function configInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
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
