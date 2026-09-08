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
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class ButBookingController
{
    public function __construct(
        private readonly ButBookingService $service,
        private readonly ParentSessionService $parentSessions,
        private readonly StaffSessionService $staffSessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/parent/booking/but', fn (Request $request): Response => $this->submit($request));
        $router->get('/parent/booking/status', fn (Request $request): Response => $this->status($request));
        $router->get('/admin/but', fn (Request $request): Response => $this->adminIndex($request));
        $router->post('/admin/but/approve', fn (Request $request): Response => $this->approve($request));
        $router->post('/admin/but/reject', fn (Request $request): Response => $this->reject($request));
    }

    private function submit(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $reservationId = $this->positiveInt($request->postString('reservation_id'), 'Reservierung');
            $bookingId = $this->service->submit($parent, $reservationId);
            $this->audit->parent($parent, 'booking.but.submitted', 'booking', $bookingId, [
                'reservation_id' => $reservationId,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/parent/booking/status?booking_id=' . $bookingId);
        } catch (DomainException $exception) {
            return $this->parentError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->technicalParentFailure($parent, $exception);
        }
    }

    private function status(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }

        try {
            $bookingId = $this->positiveInt($this->queryString($request, 'booking_id'), 'Buchung');
            $booking = $this->service->bookingForParent($parent, $bookingId);

            return Response::html($this->views->render('parent-booking-status.php', [
                'parent' => $parent,
                'booking' => $booking,
                'csrfToken' => $this->csrf->token(),
            ]));
        } catch (DomainException $exception) {
            return $this->parentError($exception->getMessage(), 404);
        }
    }

    private function adminIndex(Request $request): Response
    {
        unset($request);
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->adminPage($staff);
    }

    private function approve(Request $request): Response
    {
        return $this->review($request, true);
    }

    private function reject(Request $request): Response
    {
        return $this->review($request, false);
    }

    private function review(Request $request, bool $approved): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $bookingId = $this->positiveInt($request->postString('booking_id'), 'Buchung');
            $note = $request->postString('note');
            if ($approved) {
                $this->service->approve($staff, $bookingId, $note);
            } else {
                $this->service->reject($staff, $bookingId, $note);
            }
            $this->audit->staff(
                $staff,
                $approved ? 'booking.but.approved' : 'booking.but.rejected',
                'booking',
                $bookingId,
                ['note' => mb_substr(trim($note), 0, 4000)],
            );
            $this->csrf->rotate();

            return Response::redirect('/admin/but');
        } catch (DomainException $exception) {
            return $this->adminPage($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('BuT review failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return $this->adminPage(
                $staff,
                ['Die BuT-Prüfung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private function adminPage(AuthenticatedStaff $staff, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->views->render('but-review.php', [
            'staff' => $staff,
            'claims' => $this->service->pendingReviews(),
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    private function parent(): AuthenticatedParent|Response
    {
        $parent = $this->parentSessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        return $parent;
    }

    private function staff(): AuthenticatedStaff|Response
    {
        $staff = $this->staffSessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }

        return $staff;
    }

    private function parentError(string $message, int $status): Response
    {
        return Response::html($this->views->render('parent-booking-error.php', [
            'message' => $message,
        ]), $status);
    }

    private function technicalParentFailure(AuthenticatedParent $parent, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Parent BuT booking failed', [
            'error_id' => $errorId,
            'parent_contact_id' => $parent->id,
            'exception' => $exception,
        ]);

        return $this->parentError(
            'Die Buchung konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId,
            500,
        );
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }
}
