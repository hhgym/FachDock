<?php

declare(strict_types=1);

namespace FachDock\Operations;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Identity\OidcConfiguration;
use FachDock\Identity\OidcSessionService;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\Student\AccessCodeGenerator;
use FachDock\System\SystemStatusController;
use FachDock\System\SystemStatusService;
use FachDock\View\ViewRenderer;

final class OperationsFrontController
{
    /** @var list<string> */
    private const PATHS = [
        '/admin/system/status',
        '/admin/operations',
        '/admin/operations/report',
        '/admin/operations/update',
        '/admin/operations/emergency-opening',
        '/admin/operations/locker-status',
        '/parent/support',
        '/parent/support/report',
        '/student/support/login',
        '/student/support',
        '/student/support/report',
        '/student/support/logout',
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
            return Response::html(
                '<h1>FachDock wird aktualisiert.</h1><p>Bitte laden Sie die Seite in Kürze erneut.</p>',
                503,
            );
        }

        self::startSession();
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $logger = LoggerFactory::create($root);
        $views = new ViewRenderer((string) $config->get('paths.templates', $root . '/templates'));
        $csrf = new Csrf();
        $request = Request::fromGlobals();
        $staffSessions = new StaffSessionService(
            $pdo,
            self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $oidcConfiguration = new OidcConfiguration($config);
        $oidcSessions = new OidcSessionService(
            $pdo,
            $oidcConfiguration->sessionLifetimeMinutes(),
            60,
            $oidcConfiguration->enabled(),
        );
        $parentSessions = new ParentSessionService(
            $pdo,
            self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
        );
        $mailQueue = new MailQueueService($pdo, new MailTemplateRenderer());
        $notifications = new LockerSupportNotificationService(
            $mailQueue,
            (string) $config->get('app.base_url', ''),
            (string) $config->get('app.school_name', ''),
            $logger,
        );
        $support = new LockerSupportService($pdo, $notifications);
        $audit = new AuditLogger($pdo);

        if ($path === '/admin/system/status' && $request->method() === 'GET') {
            return (new SystemStatusController(
                new SystemStatusService($pdo, $config, $root),
                $staffSessions,
                $views,
                $csrf,
            ))->index();
        }

        $operations = new OperationsAdminController(
            $support,
            $staffSessions,
            $audit,
            $logger,
            $views,
            $csrf,
        );
        if ($path === '/admin/operations' && $request->method() === 'GET') {
            return $operations->index($request);
        }
        if ($path === '/admin/operations/report' && $request->method() === 'POST') {
            return $operations->report($request);
        }
        if ($path === '/admin/operations/update' && $request->method() === 'POST') {
            return $operations->updateIncident($request);
        }
        if ($path === '/admin/operations/emergency-opening' && $request->method() === 'POST') {
            return $operations->emergencyOpening($request);
        }
        if ($path === '/admin/operations/locker-status' && $request->method() === 'POST') {
            return $operations->updateLockerStatus($request);
        }

        $parentSupport = new ParentSupportController(
            $support,
            $parentSessions,
            $audit,
            $logger,
            $views,
            $csrf,
        );
        if ($path === '/parent/support' && $request->method() === 'GET') {
            return $parentSupport->index($request);
        }
        if ($path === '/parent/support/report' && $request->method() === 'POST') {
            return $parentSupport->report($request);
        }

        $studentSessions = new StudentSupportSessionService(
            $support,
            new AccessCodeGenerator(),
            $oidcSessions,
        );
        $studentSupport = new StudentSupportController(
            $support,
            $studentSessions,
            $logger,
            $views,
            $csrf,
        );
        if ($path === '/student/support/login' && $request->method() === 'GET') {
            return $studentSupport->loginPage($request);
        }
        if ($path === '/student/support/login' && $request->method() === 'POST') {
            return $studentSupport->login($request);
        }
        if ($path === '/student/support' && $request->method() === 'GET') {
            return $studentSupport->index($request);
        }
        if ($path === '/student/support/report' && $request->method() === 'POST') {
            return $studentSupport->report($request);
        }
        if ($path === '/student/support/logout' && $request->method() === 'POST') {
            return $studentSupport->logout($request);
        }

        return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
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
