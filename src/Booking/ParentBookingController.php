<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentPortalAccessService;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class ParentBookingController
{
    public function __construct(
        private readonly ParentBookingService $bookings,
        private readonly ParentPortalAccessService $access,
        private readonly ParentSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
        private readonly int $recommendationCount = 3,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/parent/booking', fn (Request $request): Response => $this->index($request));
        $router->post('/parent/booking/reserve', fn (Request $request): Response => $this->reserve($request));
        $router->post('/parent/booking/cancel', fn (Request $request): Response => $this->cancel($request));
    }

    private function index(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }

        $studentValue = $this->queryString($request, 'student_id');
        $yearValue = $this->queryString($request, 'school_year_id');
        if ($studentValue === '' || $yearValue === '') {
            return $this->page(
                $parent,
                [],
                200,
                $studentValue === '' ? null : $this->optionalPositiveInt($studentValue),
                $yearValue === '' ? null : $this->optionalPositiveInt($yearValue),
            );
        }

        try {
            return $this->selectedPage(
                $parent,
                $this->positiveInt($studentValue, 'Schüler'),
                $this->positiveInt($yearValue, 'Schuljahr'),
            );
        } catch (DomainException $exception) {
            return $this->page($parent, [$exception->getMessage()], 422);
        }
    }

    private function reserve(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $studentId = 0;
        $schoolYearId = 0;
        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $schoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $lockerId = $this->positiveInt($request->postString('locker_id'), 'Schließfach');
            $reservationId = $this->bookings->reserve($parent, $studentId, $schoolYearId, $lockerId);

            $this->audit->parent($parent, 'booking.reservation.created', 'locker_reservation', $reservationId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $lockerId,
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($studentId, $schoolYearId));
        } catch (DomainException $exception) {
            return $this->selectionFailure($parent, $studentId, $schoolYearId, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($parent, $studentId, $schoolYearId, $exception);
        }
    }

    private function cancel(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $studentId = 0;
        $schoolYearId = 0;
        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $schoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $reservationId = $this->positiveInt($request->postString('reservation_id'), 'Reservierung');
            $reservation = $this->bookings->cancelReservation(
                $parent,
                $studentId,
                $schoolYearId,
                $reservationId,
            );

            $this->audit->parent($parent, 'booking.reservation.cancelled', 'locker_reservation', $reservationId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $reservation['locker_id'] ?? null,
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($studentId, $schoolYearId));
        } catch (DomainException $exception) {
            return $this->selectionFailure($parent, $studentId, $schoolYearId, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($parent, $studentId, $schoolYearId, $exception);
        }
    }

    /** @param list<string> $errors */
    private function selectedPage(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        array $errors = [],
        int $status = 200,
    ): Response {
        $selection = $this->bookings->selection(
            $parent,
            $studentId,
            $schoolYearId,
            $this->recommendationCount,
        );

        return $this->page(
            $parent,
            $errors,
            $status,
            $studentId,
            $schoolYearId,
            $selection,
        );
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $selection
     */
    private function page(
        AuthenticatedParent $parent,
        array $errors = [],
        int $status = 200,
        ?int $selectedStudentId = null,
        ?int $selectedSchoolYearId = null,
        ?array $selection = null,
    ): Response {
        return Response::html($this->views->render('parent-booking.php', [
            'parent' => $parent,
            'children' => $this->access->children($parent->id),
            'schoolYears' => $this->bookings->bookableSchoolYears(),
            'selectedStudentId' => $selectedStudentId,
            'selectedSchoolYearId' => $selectedSchoolYearId,
            'selection' => $selection,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    private function parent(): AuthenticatedParent|Response
    {
        $parent = $this->sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        return $parent;
    }

    private function selectionFailure(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        string $message,
        int $status,
    ): Response {
        if ($studentId > 0 && $schoolYearId > 0) {
            try {
                return $this->selectedPage($parent, $studentId, $schoolYearId, [$message], $status);
            } catch (Throwable) {
                // The selection may no longer be loadable after a concurrent change.
            }
        }

        return $this->page($parent, [$message], $status);
    }

    private function technicalFailure(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        Throwable $exception,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Parent booking selection failed', [
            'error_id' => $errorId,
            'parent_contact_id' => $parent->id,
            'student_id' => $studentId > 0 ? $studentId : null,
            'school_year_id' => $schoolYearId > 0 ? $schoolYearId : null,
            'exception' => $exception,
        ]);

        return $this->selectionFailure(
            $parent,
            $studentId,
            $schoolYearId,
            'Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId,
            500,
        );
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function optionalPositiveInt(string $value): ?int
    {
        return preg_match('/^\d+$/', trim($value)) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function selectionUrl(int $studentId, int $schoolYearId): string
    {
        return '/parent/booking?student_id=' . $studentId . '&school_year_id=' . $schoolYearId;
    }
}
