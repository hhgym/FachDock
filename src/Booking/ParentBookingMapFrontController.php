<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\FloorPlan\FloorPlanService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Throwable;

final class ParentBookingMapFrontController
{
    private const PATH = '/parent/booking/map';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== self::PATH) {
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
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
            }

            $pdo = ConnectionFactory::fromConfig($config);
            $sessions = new ParentSessionService(
                $pdo,
                self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
                self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
            );
            $parent = $sessions->current();
            if ($parent === null) {
                return Response::redirect('/parent/login');
            }

            $request = Request::fromGlobals();
            $studentId = self::positiveInt(self::queryString($request, 'student_id'), 'Schüler');
            $schoolYearId = self::optionalPositiveInt(self::queryString($request, 'school_year_id'));
            $floorId = self::optionalPositiveInt(self::queryString($request, 'floor_id'));
            $planId = self::optionalPositiveInt(self::queryString($request, 'plan_id'));

            $evaluator = new AllocationRuleEvaluator($pdo);
            $ranker = new LockerRecommendationRanker();
            $gradeResolver = new ProjectedGradeResolver();
            $recommendations = new LockerRecommendationService($pdo, $evaluator, $ranker, $gradeResolver);
            $reservations = new ReservationService(
                $pdo,
                self::configInt($config, 'booking.reservation_minutes', 15),
                self::configInt($config, 'booking.payment_grace_minutes', 30),
                $evaluator,
                $gradeResolver,
            );
            $bookings = new ParentBookingService($pdo, $recommendations, $reservations, $ranker);
            $child = $bookings->linkedChild($parent, $studentId);
            $selection = null;
            if ($schoolYearId !== null) {
                $selection = (new ParentBookingMapService(
                    $bookings,
                    new FloorPlanService($pdo, $root),
                ))->selection(
                    $parent,
                    $studentId,
                    $schoolYearId,
                    $floorId,
                    $planId,
                    self::configInt($config, 'booking.recommendation_count', 3),
                );
            }

            return self::page(
                $config,
                $parent,
                $child,
                $bookings->bookableSchoolYears(),
                $schoolYearId,
                $selection,
                new ViewRenderer((string) $config->get('paths.templates', $root . '/templates')),
                new Csrf(),
            );
        } catch (DomainException $exception) {
            try {
                $pdo = ConnectionFactory::fromConfig($config);
                $sessions = new ParentSessionService(
                    $pdo,
                    self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
                    self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
                );
                $parent = $sessions->current();
                if ($parent === null) {
                    return Response::redirect('/parent/login');
                }

                return Response::html(
                    (new ViewRenderer((string) $config->get('paths.templates', $root . '/templates')))->render(
                        'parent-booking-error.php',
                        ['message' => $exception->getMessage()],
                    ),
                    422,
                );
            } catch (Throwable) {
                return Response::html('<h1>Ungültige Auswahl</h1>', 422);
            }
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $logger->error('Parent floorplan booking failed', [
                'error_id' => $errorId,
                'exception' => $exception,
            ]);

            return Response::html(
                '<h1>Ein Fehler ist aufgetreten.</h1><p>Fehler-ID: <code>' . $errorId . '</code></p>',
                500,
            );
        }
    }

    /**
     * @param array{id:int,first_name:string,last_name:string,class_name:string,grade:int} $child
     * @param list<array{id:int,label:string,starts_on:string,ends_on:string,annual_fee_cents:int}> $schoolYears
     * @param array<string, mixed>|null $selection
     */
    private static function page(
        Config $config,
        AuthenticatedParent $parent,
        array $child,
        array $schoolYears,
        ?int $schoolYearId,
        ?array $selection,
        ViewRenderer $views,
        Csrf $csrf,
    ): Response {
        return Response::html($views->render('parent-booking-map.php', [
            'appName' => (string) $config->get('app.name', 'FachDock'),
            'parent' => $parent,
            'child' => $child,
            'schoolYears' => $schoolYears,
            'selectedSchoolYearId' => $schoolYearId,
            'selection' => $selection,
            'csrfToken' => $csrf->token(),
        ]));
    }

    private static function positiveInt(string $value, string $label): int
    {
        $parsed = self::optionalPositiveInt($value);
        if ($parsed === null) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return $parsed;
    }

    private static function optionalPositiveInt(string $value): ?int
    {
        return preg_match('/^\d+$/', trim($value)) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    private static function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
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
