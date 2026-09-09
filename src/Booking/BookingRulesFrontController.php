<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
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
use FachDock\Mail\BookingLifecycleNotificationService;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class BookingRulesFrontController
{
    /** @var list<string> */
    private const PATHS = [
        '/parent/bookings',
        '/parent/bookings/change-locker',
        '/parent/bookings/renew',
        '/admin/config/booking/self-service',
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
        $audit = new AuditLogger($pdo);
        $changeLimit = self::configNonNegativeInt($config, 'booking.self_service_change_limit', 2, 20);

        if ($path === '/admin/config/booking/self-service') {
            return self::settings(
                $request,
                $root,
                $staffSessions,
                $audit,
                $logger,
                $views,
                $csrf,
                $changeLimit,
            );
        }

        $sessions = new ParentSessionService(
            $pdo,
            self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $parent = $sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        $service = new BookingSelfServiceService(
            $pdo,
            new AllocationRuleEvaluator($pdo),
            self::configInt($config, 'booking.but_rejection_payment_days', 14),
        );
        $notifications = new BookingLifecycleNotificationService(
            $pdo,
            new MailQueueService($pdo, new MailTemplateRenderer()),
            (string) $config->get('app.base_url', ''),
            (string) $config->get('app.school_name', ''),
            $logger,
        );

        if ($path === '/parent/bookings' && $request->method() === 'GET') {
            $success = self::queryString($request, 'changed') === '1'
                ? 'Das Schließfach wurde erfolgreich gewechselt.'
                : null;

            return self::page($parent, $service, $views, $csrf, $changeLimit, [], $success);
        }
        if ($path === '/parent/bookings/change-locker' && $request->method() === 'POST') {
            return self::changeLocker(
                $request,
                $parent,
                $service,
                $notifications,
                $audit,
                $logger,
                $views,
                $csrf,
                $changeLimit,
            );
        }
        if ($path === '/parent/bookings/renew' && $request->method() === 'POST') {
            return self::renew(
                $request,
                $parent,
                $service,
                $notifications,
                $audit,
                $logger,
                $views,
                $csrf,
                $changeLimit,
            );
        }

        return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
    }

    private static function settings(
        Request $request,
        string $root,
        StaffSessionService $sessions,
        AuditLogger $audit,
        LoggerInterface $logger,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
    ): Response {
        $staff = $sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        if ($request->method() === 'GET') {
            return self::settingsPage(
                $staff,
                $views,
                $csrf,
                $changeLimit,
                [],
                self::queryString($request, 'saved') === '1',
            );
        }
        if ($request->method() !== 'POST') {
            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        }
        if (!$csrf->verify($request->postString('_csrf'))) {
            return self::settingsPage(
                $staff,
                $views,
                $csrf,
                $changeLimit,
                ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'],
                false,
                419,
            );
        }

        $value = trim($request->postString('self_service_change_limit'));
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value > 20) {
            return self::settingsPage(
                $staff,
                $views,
                $csrf,
                $changeLimit,
                ['Das Wechsel-Limit muss zwischen 0 und 20 liegen.'],
                false,
                422,
            );
        }
        $newLimit = (int) $value;
        try {
            (new LocalConfigWriter($root))->saveBookingSettings(['self_service_change_limit' => $newLimit]);
            $audit->staff($staff, 'system.booking.self_service_settings.updated', 'system', 'booking', [
                'self_service_change_limit' => $newLimit,
            ]);
            $csrf->rotate();

            return Response::redirect('/admin/config/booking/self-service?saved=1');
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $logger->error('Booking self-service settings update failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return self::settingsPage(
                $staff,
                $views,
                $csrf,
                $changeLimit,
                ['Die Einstellung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                false,
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private static function settingsPage(
        AuthenticatedStaff $staff,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
        array $errors,
        bool $success,
        int $status = 200,
    ): Response {
        return Response::html($views->render('config-booking-self-service.php', [
            'staff' => $staff,
            'csrfToken' => $csrf->token(),
            'changeLimit' => $changeLimit,
            'errors' => $errors,
            'success' => $success,
        ]), $status);
    }

    private static function changeLocker(
        Request $request,
        AuthenticatedParent $parent,
        BookingSelfServiceService $service,
        BookingLifecycleNotificationService $notifications,
        AuditLogger $audit,
        LoggerInterface $logger,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
    ): Response {
        if (!$csrf->verify($request->postString('_csrf'))) {
            return self::page(
                $parent,
                $service,
                $views,
                $csrf,
                $changeLimit,
                ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'],
                null,
                419,
            );
        }

        try {
            $bookingId = self::positiveInt($request->postString('booking_id'), 'Buchung');
            $lockerId = self::positiveInt($request->postString('locker_id'), 'Schließfach');
            $service->changeLocker($parent, $bookingId, $lockerId, $changeLimit);
            $audit->parent($parent, 'booking.locker.changed', 'booking', $bookingId, [
                'locker_id' => $lockerId,
                'self_service' => true,
                'configured_change_limit' => $changeLimit,
            ]);
            $notifications->lockerChanged($bookingId);
            $csrf->rotate();

            return Response::redirect('/parent/bookings?changed=1');
        } catch (DomainException $exception) {
            return self::page(
                $parent,
                $service,
                $views,
                $csrf,
                $changeLimit,
                [$exception->getMessage()],
                null,
                422,
            );
        } catch (Throwable $exception) {
            return self::technicalFailure(
                $parent,
                $service,
                $logger,
                $views,
                $csrf,
                $changeLimit,
                'Parent locker change failed',
                $exception,
            );
        }
    }

    private static function renew(
        Request $request,
        AuthenticatedParent $parent,
        BookingSelfServiceService $service,
        BookingLifecycleNotificationService $notifications,
        AuditLogger $audit,
        LoggerInterface $logger,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
    ): Response {
        if (!$csrf->verify($request->postString('_csrf'))) {
            return self::page(
                $parent,
                $service,
                $views,
                $csrf,
                $changeLimit,
                ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'],
                null,
                419,
            );
        }

        try {
            $bookingId = self::positiveInt($request->postString('booking_id'), 'Buchung');
            $schoolYearId = self::positiveInt($request->postString('school_year_id'), 'Zielschuljahr');
            $requestBut = $request->postString('request_but') === '1';
            $newBookingId = $service->renew($parent, $bookingId, $schoolYearId, $requestBut);
            $audit->parent($parent, 'booking.renewed', 'booking', $bookingId, [
                'new_booking_id' => $newBookingId,
                'school_year_id' => $schoolYearId,
                'but_requested' => $requestBut,
                'self_service' => true,
            ]);
            $notifications->renewed($newBookingId);
            $csrf->rotate();

            return Response::redirect('/parent/booking/status?booking_id=' . $newBookingId . '&renewed=1');
        } catch (DomainException $exception) {
            return self::page(
                $parent,
                $service,
                $views,
                $csrf,
                $changeLimit,
                [$exception->getMessage()],
                null,
                422,
            );
        } catch (Throwable $exception) {
            return self::technicalFailure(
                $parent,
                $service,
                $logger,
                $views,
                $csrf,
                $changeLimit,
                'Parent booking renewal failed',
                $exception,
            );
        }
    }

    /** @param list<string> $errors */
    private static function page(
        AuthenticatedParent $parent,
        BookingSelfServiceService $service,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
        array $errors,
        ?string $success,
        int $status = 200,
    ): Response {
        $bookings = $service->bookingsForParent($parent, $changeLimit);
        foreach ($bookings as &$booking) {
            $canChange = $parent->adminPreview
                || ((int) ($booking['changes_remaining'] ?? 0) > 0);
            $booking['locker_options'] = $canChange
                ? $service->lockerOptions($parent, (int) $booking['booking_id'])
                : [];
            $booking['renewal_plans'] = (string) $booking['status'] === BookingStatus::Active->value
                ? $service->renewalPlans($parent, (int) $booking['booking_id'])
                : [];
        }
        unset($booking);

        return Response::html($views->render('parent-bookings.php', [
            'parent' => $parent,
            'bookings' => $bookings,
            'changeLimit' => $changeLimit,
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
            'success' => $success,
        ]), $status);
    }

    private static function technicalFailure(
        AuthenticatedParent $parent,
        BookingSelfServiceService $service,
        LoggerInterface $logger,
        ViewRenderer $views,
        Csrf $csrf,
        int $changeLimit,
        string $message,
        Throwable $exception,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $logger->error($message, [
            'error_id' => $errorId,
            'parent_contact_id' => $parent->id,
            'exception' => $exception,
        ]);

        return self::page(
            $parent,
            $service,
            $views,
            $csrf,
            $changeLimit,
            ['Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
            null,
            500,
        );
    }

    private static function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
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

    private static function configNonNegativeInt(Config $config, string $key, int $default, int $max): int
    {
        $value = $config->get($key, $default);
        if (!is_numeric($value)) {
            return $default;
        }

        return max(0, min($max, (int) $value));
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
