<?php

declare(strict_types=1);

namespace FachDock\Identity;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\StaffSessionService;
use FachDock\Auth\StaffUserManagementService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Privacy\AccountLifecycleService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;

final class PersonAccountAdminFrontController
{
    /** @var list<string> */
    private const PATHS = [
        '/admin/student-data',
        '/admin/accounts',
        '/admin/accounts/student/deactivate',
        '/admin/accounts/student/reactivate',
        '/admin/accounts/student/anonymize',
        '/admin/accounts/parent/deactivate',
        '/admin/accounts/parent/reactivate',
        '/admin/accounts/parent/anonymize',
        '/admin/accounts/lifecycle/run',
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
        $sessions = new StaffSessionService(
            $pdo,
            self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $lifecycle = new AccountLifecycleService($pdo);
        $router = new Router();
        (new PersonAccountAdminController(
            new PersonAccountAdminService($pdo, $lifecycle),
            new StaffUserManagementService(
                $pdo,
                $sessions,
                self::configInt($config, 'auth.password_min_length', 12),
            ),
            $lifecycle,
            $sessions,
            new AuditLogger($pdo),
            LoggerFactory::create($root),
            new ViewRenderer((string) $config->get('paths.templates', $root . '/templates')),
            new Csrf(),
        ))->register($router);

        return $router->dispatch(Request::fromGlobals());
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
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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
