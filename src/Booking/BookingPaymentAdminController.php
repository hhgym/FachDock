<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Payment\PaidPaymentRecoveryService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class BookingPaymentAdminController
{
    public function __construct(
        private readonly BookingPaymentAdminService $service,
        private readonly PaidPaymentRecoveryService $recovery,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/bookings', fn (Request $request): Response => $this->bookings($request));
        $router->get('/admin/bookings/detail', fn (Request $request): Response => $this->bookingDetail($request));
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
            $booking = $this->service->booking($bookingId);
        } catch (DomainException $exception) {
            return Response::html($this->views->render('admin-record-not-found.php', [
                'staff' => $staff,
                'title' => 'Buchung nicht gefunden',
                'message' => $exception->getMessage(),
                'backUrl' => '/admin/bookings',
                'backLabel' => 'Zur Buchungsübersicht',
                'csrfToken' => $this->csrf->token(),
            ]), 404);
        }

        return Response::html($this->views->render('admin-booking-detail.php', [
            'staff' => $staff,
            'booking' => $booking,
            'csrfToken' => $this->csrf->token(),
        ]));
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
