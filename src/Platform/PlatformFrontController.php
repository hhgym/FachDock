<?php

declare(strict_types=1);

namespace FachDock\Platform;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\StaffSessionService;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Export\AdminExportService;
use FachDock\FloorPlan\FloorPlanController;
use FachDock\FloorPlan\FloorPlanService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Parent\ParentSessionService;
use FachDock\Privacy\DataRetentionService;
use FachDock\Privacy\PrivacyController;
use FachDock\SchoolYear\SchoolYearTransitionController;
use FachDock\SchoolYear\SchoolYearTransitionService;
use FachDock\Security\Csrf;
use FachDock\System\ProductionReadinessService;
use FachDock\View\ViewRenderer;
use Throwable;

final class PlatformFrontController
{
    /** @var list<string> */
    private const PATHS = [
        '/admin/floorplans',
        '/admin/floorplans/upload',
        '/admin/floorplans/placement',
        '/admin/floorplans/placement/remove',
        '/admin/floorplans/delete',
        '/parent/floorplans',
        '/floorplans/image',
        '/admin/school-year-transition',
        '/admin/school-year-transition/remind',
        '/admin/school-year-transition/apply',
        '/admin/privacy',
        '/admin/privacy/settings',
        '/admin/privacy/anonymize',
        '/admin/privacy/export',
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
        $logger = LoggerFactory::create($root);

        try {
            $pdo = ConnectionFactory::fromConfig($config);
            $views = new ViewRenderer((string) $config->get('paths.templates', $root . '/templates'));
            $csrf = new Csrf();
            $request = Request::fromGlobals();
            $staffSessions = new StaffSessionService(
                $pdo,
                self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
                self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
            );
            $parentSessions = new ParentSessionService(
                $pdo,
                self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
            );
            $audit = new AuditLogger($pdo);

            $floorPlans = new FloorPlanController(
                new FloorPlanService($pdo, $root),
                $staffSessions,
                $parentSessions,
                $audit,
                $logger,
                $views,
                $csrf,
            );
            if ($path === '/admin/floorplans' && $request->method() === 'GET') {
                return $floorPlans->adminIndex($request);
            }
            if ($path === '/parent/floorplans' && $request->method() === 'GET') {
                return $floorPlans->parentIndex($request);
            }
            if ($path === '/floorplans/image' && $request->method() === 'GET') {
                return $floorPlans->image($request);
            }
            if ($path === '/admin/floorplans/upload' && $request->method() === 'POST') {
                return $floorPlans->upload($request);
            }
            if ($path === '/admin/floorplans/placement' && $request->method() === 'POST') {
                return $floorPlans->placement($request);
            }
            if ($path === '/admin/floorplans/placement/remove' && $request->method() === 'POST') {
                return $floorPlans->removePlacement($request);
            }
            if ($path === '/admin/floorplans/delete' && $request->method() === 'POST') {
                return $floorPlans->delete($request);
            }

            $mailQueue = new MailQueueService($pdo, new MailTemplateRenderer());
            $transitions = new SchoolYearTransitionController(
                new SchoolYearTransitionService(
                    $pdo,
                    new AllocationRuleEvaluator($pdo),
                    $mailQueue,
                    (string) $config->get('app.base_url', ''),
                    (string) $config->get('app.school_name', ''),
                ),
                $staffSessions,
                $audit,
                $logger,
                $views,
                $csrf,
            );
            if ($path === '/admin/school-year-transition' && $request->method() === 'GET') {
                return $transitions->index($request);
            }
            if ($path === '/admin/school-year-transition/remind' && $request->method() === 'POST') {
                return $transitions->remind($request);
            }
            if ($path === '/admin/school-year-transition/apply' && $request->method() === 'POST') {
                return $transitions->apply($request);
            }

            $privacy = new PrivacyController(
                new DataRetentionService($pdo),
                new AdminExportService($pdo),
                new ProductionReadinessService($pdo, $config, $root),
                $staffSessions,
                $audit,
                $logger,
                $views,
                $csrf,
            );
            if ($path === '/admin/privacy' && $request->method() === 'GET') {
                return $privacy->index($request);
            }
            if ($path === '/admin/privacy/settings' && $request->method() === 'POST') {
                return $privacy->saveSettings($request);
            }
            if ($path === '/admin/privacy/anonymize' && $request->method() === 'POST') {
                return $privacy->anonymize($request);
            }
            if ($path === '/admin/privacy/export' && $request->method() === 'GET') {
                return $privacy->export($request);
            }

            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $logger->error('Platform front controller failed', ['error_id' => $errorId, 'exception' => $exception]);

            return Response::html(
                '<h1>Ein Fehler ist aufgetreten.</h1><p>Fehler-ID: <code>' . $errorId . '</code></p>',
                500,
            );
        }
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
