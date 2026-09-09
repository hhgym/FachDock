<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Mail\BookingLifecycleNotificationService;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Payment\PaidPaymentRecoveryService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class BookingPaymentAdminController
{
    private readonly BookingLifecycleService $lifecycle;
    private readonly BookingLifecycleAdminService $lifecycleAdmin;
    private readonly BookingLifecycleNotificationService $lifecycleNotifications;

    public function __construct(
        private readonly BookingPaymentAdminService $service,
        private readonly PaidPaymentRecoveryService $recovery,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
        ?BookingLifecycleService $lifecycle = null,
        ?BookingLifecycleAdminService $lifecycleAdmin = null,
        ?BookingLifecycleNotificationService $lifecycleNotifications = null,
    ) {
        if ($lifecycle !== null && $lifecycleAdmin !== null && $lifecycleNotifications !== null) {
            $this->lifecycle = $lifecycle;
            $this->lifecycleAdmin = $lifecycleAdmin;
            $this->lifecycleNotifications = $lifecycleNotifications;

            return;
        }

        $root = dirname(__DIR__, 2);
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $allocationRules = new AllocationRuleEvaluator($pdo);
        $this->lifecycle = $lifecycle ?? new BookingLifecycleService(
            $pdo,
            $allocationRules,
            (int) $config->get('booking.but_rejection_payment_days', 14),
        );
        $this->lifecycleAdmin = $lifecycleAdmin ?? new BookingLifecycleAdminService($pdo, $allocationRules);
        $this->lifecycleNotifications = $lifecycleNotifications ?? new BookingLifecycleNotificationService(
            $pdo,
            new MailQueueService($pdo, new MailTemplateRenderer()),
            (string) $config->get('app.base_url', ''),
            (string) $config->get('app.school_name', ''),
            $logger,
        );
    }

    public function register(Router $router): void
    {
        $router->get('/admin/bookings', fn (Request $request): Response => $this->bookings($request));
        $router->get('/admin/bookings/detail', fn (Request $request): Response => $this->bookingDetail($request));
        $router->post('/admin/bookings/change-locker', fn (Request $request): Response => $this->changeLocker($request));
        $router->post('/admin/bookings/end', fn (Request $request): Response => $this->endBooking($request, false));
        $router->post('/admin/bookings/cancel', fn (Request $request): Response => $this->endBooking($request, true));
        $router->post('/admin/bookings/renew', fn (Request $request): Response => $this->renewBooking($request));
        $router->get('/admin/payments', fn (Request $request): Response => $this->payments($request));
        $router->get('/admin/payments/detail', fn (Request $request): Response => $this->paymentDetail($request));
        $router->post('/admin/payments/recover', fn (Request $request): Response => $this->recoverPayment($request));
    }

    private function bookings(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        $schoolYearId = $this->optionalPositiveInt($this->queryString($request, 'school_year_id'));
        $status = $this->bookingStatus($this->queryString($request, 'status'));
        $query = mb_substr($this->queryString($request, 'q'), 0, 120);

        return Response::html($this->views->render('admin-bookings.php', [
            'staff' => $staff,
            'schoolYears' => $this->service->schoolYears(),
            'bookings' => $this->service->bookings($schoolYearId, $status, $query),
            'selectedSchoolYearId' => $schoolYearId,
            'selectedStatus' => $status,
            'query' => $query,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    private function bookingDetail(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        try {
            $bookingId = $this->positiveInt($this->queryString($request, 'id'), 'Buchung');
        } catch (DomainException $exception) {
            return $this->bookingNotFound($staff, $exception->getMessage());
        }

        $success = match (true) {
            $this->queryString($request, 'locker_changed') === '1' => 'Das Schließfach wurde geändert.',
            $this->queryString($request, 'ended') === '1' => 'Die Buchung wurde beendet und das Schließfach freigegeben.',
            $this->queryString($request, 'cancelled') === '1' => 'Die Buchung wurde storniert und das Schließfach freigegeben.',
            $this->queryString($request, 'renewed') === '1' => 'Die Verlängerungsbuchung wurde angelegt.',
            default => null,
        };

        return $this->bookingDetailPage($staff, $bookingId, [], $success);
    }

    private function changeLocker(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $bookingId = 0;
        try {
            $bookingId = $this->positiveInt($request->postString('booking_id'), 'Buchung');
            $lockerId = $this->positiveInt($request->postString('locker_id'), 'Schließfach');
            $reason = $request->postString('reason');
            $this->lifecycle->changeLocker($staff, $bookingId, $lockerId, $reason);
            $this->audit->staff($staff, 'booking.locker.changed', 'booking', $bookingId, [
                'locker_id' => $lockerId,
                'reason' => mb_substr(trim($reason), 0, 1000),
            ]);
            $this->lifecycleNotifications->lockerChanged($bookingId);
            $this->csrf->rotate();

            return Response::redirect('/admin/bookings/detail?id=' . $bookingId . '&locker_changed=1');
        } catch (DomainException $exception) {
            return $this->bookingActionError($staff, $bookingId, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->bookingTechnicalError($staff, $bookingId, 'Locker change failed', $exception);
        }
    }

    private function endBooking(Request $request, bool $cancelled): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $bookingId = 0;
        try {
            $bookingId = $this->positiveInt($request->postString('booking_id'), 'Buchung');
            $reason = $request->postString('reason');
            $this->lifecycle->end($staff, $bookingId, $reason, $cancelled);
            $this->audit->staff(
                $staff,
                $cancelled ? 'booking.cancelled' : 'booking.ended',
                'booking',
                $bookingId,
                ['reason' => mb_substr(trim($reason), 0, 1000)],
            );
            $this->lifecycleNotifications->ended($bookingId, $cancelled);
            $this->csrf->rotate();

            return Response::redirect(
                '/admin/bookings/detail?id=' . $bookingId . ($cancelled ? '&cancelled=1' : '&ended=1')
            );
        } catch (DomainException $exception) {
            return $this->bookingActionError($staff, $bookingId, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->bookingTechnicalError($staff, $bookingId, 'Booking termination failed', $exception);
        }
    }

    private function renewBooking(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $bookingId = 0;
        try {
            $bookingId = $this->positiveInt($request->postString('booking_id'), 'Buchung');
            $targetSchoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Zielschuljahr');
            $requestBut = $request->postString('request_but') === '1';
            $newBookingId = $this->lifecycle->renew($staff, $bookingId, $targetSchoolYearId, $requestBut);
            $this->audit->staff($staff, 'booking.renewed', 'booking', $bookingId, [
                'new_booking_id' => $newBookingId,
                'school_year_id' => $targetSchoolYearId,
                'but_requested' => $requestBut,
            ]);
            $this->lifecycleNotifications->renewed($newBookingId);
            $this->csrf->rotate();

            return Response::redirect('/admin/bookings/detail?id=' . $newBookingId . '&renewed=1');
        } catch (DomainException $exception) {
            return $this->bookingActionError($staff, $bookingId, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->bookingTechnicalError($staff, $bookingId, 'Booking renewal failed', $exception);
        }
    }

    /** @param list<string> $errors */
    private function bookingDetailPage(
        AuthenticatedStaff $staff,
        int $bookingId,
        array $errors,
        ?string $success = null,
        int $status = 200,
    ): Response {
        try {
            $booking = $this->service->booking($bookingId);
        } catch (DomainException $exception) {
            return $this->bookingNotFound($staff, $exception->getMessage());
        }

        return Response::html($this->views->render('admin-booking-detail.php', [
            'staff' => $staff,
            'booking' => $booking,
            'renewalTargets' => $this->lifecycleAdmin->renewalTargets($bookingId),
            'lockerOptions' => $this->lifecycleAdmin->lockerOptions($bookingId),
            'lifecycleEvents' => $this->lifecycleAdmin->events($bookingId),
            'errors' => $errors,
            'success' => $success,
            'csrfToken' => $this->csrf->token(),
        ]), $status);
    }

    private function bookingActionError(
        AuthenticatedStaff $staff,
        int $bookingId,
        string $message,
        int $status,
    ): Response {
        if ($bookingId < 1) {
            return $this->bookingNotFound($staff, $message);
        }

        return $this->bookingDetailPage($staff, $bookingId, [$message], null, $status);
    }

    private function bookingTechnicalError(
        AuthenticatedStaff $staff,
        int $bookingId,
        string $logMessage,
        Throwable $exception,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error($logMessage, [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'booking_id' => $bookingId > 0 ? $bookingId : null,
            'exception' => $exception,
        ]);
        if ($bookingId < 1) {
            return Response::html('<h1>Ein Fehler ist aufgetreten.</h1><p>Fehler-ID: ' . $errorId . '</p>', 500);
        }

        return $this->bookingDetailPage(
            $staff,
            $bookingId,
            ['Die Änderung konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
            null,
            500,
        );
    }

    private function bookingNotFound(AuthenticatedStaff $staff, string $message): Response
    {
        return Response::html($this->views->render('admin-record-not-found.php', [
            'staff' => $staff,
            'title' => 'Buchung nicht gefunden',
            'message' => $message,
            'backUrl' => '/admin/bookings',
            'backLabel' => 'Zur Buchungsübersicht',
            'csrfToken' => $this->csrf->token(),
        ]), 404);
    }

    private function payments(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        $schoolYearId = $this->optionalPositiveInt($this->queryString($request, 'school_year_id'));
        $status = $this->paymentStatus($this->queryString($request, 'status'));
        $query = mb_substr($this->queryString($request, 'q'), 0, 120);

        return Response::html($this->views->render('admin-payments.php', [
            'staff' => $staff,
            'schoolYears' => $this->service->schoolYears(),
            'payments' => $this->service->payments($schoolYearId, $status, $query),
            'selectedSchoolYearId' => $schoolYearId,
            'selectedStatus' => $status,
            'query' => $query,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    private function paymentDetail(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        try {
            $paymentId = $this->positiveInt($this->queryString($request, 'id'), 'Zahlung');
        } catch (DomainException $exception) {
            return $this->paymentNotFound($staff, $exception->getMessage());
        }

        return $this->paymentDetailPage(
            $staff,
            $paymentId,
            [],
            $this->queryString($request, 'recovered') === '1',
        );
    }

    private function recoverPayment(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $paymentId = 0;
        try {
            $paymentId = $this->positiveInt($request->postString('payment_id'), 'Zahlung');
            $bookingId = $this->recovery->recover($paymentId);
            $this->audit->staff($staff, 'payment.manual_review.recovered', 'payment', $paymentId, [
                'booking_id' => $bookingId,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/payments/detail?id=' . $paymentId . '&recovered=1');
        } catch (DomainException $exception) {
            if ($paymentId < 1) {
                return $this->paymentNotFound($staff, $exception->getMessage());
            }

            return $this->paymentDetailPage($staff, $paymentId, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Manual payment recovery failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'payment_id' => $paymentId > 0 ? $paymentId : null,
                'exception' => $exception,
            ]);

            if ($paymentId < 1) {
                return Response::html('<h1>Ein Fehler ist aufgetreten.</h1><p>Fehler-ID: ' . $errorId . '</p>', 500);
            }

            return $this->paymentDetailPage(
                $staff,
                $paymentId,
                ['Die Wiederherstellung konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
                false,
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private function paymentDetailPage(
        AuthenticatedStaff $staff,
        int $paymentId,
        array $errors,
        bool $recovered,
        int $status = 200,
    ): Response {
        try {
            $payment = $this->service->payment($paymentId);
        } catch (DomainException $exception) {
            return $this->paymentNotFound($staff, $exception->getMessage());
        }

        return Response::html($this->views->render('admin-payment-detail.php', [
            'staff' => $staff,
            'payment' => $payment,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'recovered' => $recovered,
        ]), $status);
    }

    private function paymentNotFound(AuthenticatedStaff $staff, string $message): Response
    {
        return Response::html($this->views->render('admin-record-not-found.php', [
            'staff' => $staff,
            'title' => 'Zahlung nicht gefunden',
            'message' => $message,
            'backUrl' => '/admin/payments',
            'backLabel' => 'Zur Zahlungsübersicht',
            'csrfToken' => $this->csrf->token(),
        ]), 404);
    }

    private function staff(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }

        return $staff;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function optionalPositiveInt(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        return preg_match('/^\d+$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function bookingStatus(string $status): ?string
    {
        if ($status === '') {
            return null;
        }

        $allowed = array_map(static fn (BookingStatus $value): string => $value->value, BookingStatus::cases());

        return in_array($status, $allowed, true) ? $status : null;
    }

    private function paymentStatus(string $status): ?string
    {
        if ($status === '') {
            return null;
        }

        $allowed = [
            'creating',
            'checkout_open',
            'processing_paid',
            'paid',
            'failed',
            'expired',
            'manual_review',
        ];

        return in_array($status, $allowed, true) ? $status : null;
    }
}
