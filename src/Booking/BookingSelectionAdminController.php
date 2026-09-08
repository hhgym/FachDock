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
use FachDock\SchoolYear\SchoolYearService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class BookingSelectionAdminController
{
    public function __construct(
        private readonly LockerRecommendationService $recommendations,
        private readonly ReservationService $reservations,
        private readonly SchoolYearService $schoolYears,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
        private readonly int $recommendationCount = 3,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/booking-selection', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/booking-selection/reserve', fn (Request $request): Response => $this->reserve($request));
        $router->post('/admin/booking-selection/cancel', fn (Request $request): Response => $this->cancel($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        $studentValue = $this->queryString($request, 'student_id');
        $yearValue = $this->queryString($request, 'school_year_id');
        if ($studentValue === '' && $yearValue === '') {
            return $this->page($staff);
        }

        try {
            return $this->selectedPage(
                $staff,
                $this->positiveInt($studentValue, 'Schüler'),
                $this->positiveInt($yearValue, 'Schuljahr'),
            );
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        }
    }

    private function reserve(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
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

            $reservationId = $this->reservations->reserve(
                $studentId,
                $schoolYearId,
                $lockerId,
                null,
                true,
            );
            $reservation = $this->reservations->activeForStudent($studentId, $schoolYearId);
            $this->audit->staff($staff, 'booking.reservation.created', 'locker_reservation', $reservationId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $lockerId,
                'projected_grade' => $reservation['projected_grade'] ?? null,
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($studentId, $schoolYearId));
        } catch (DomainException $exception) {
            if ($studentId > 0 && $schoolYearId > 0) {
                return $this->selectedPage($staff, $studentId, $schoolYearId, [$exception->getMessage()], 422);
            }

            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception, $studentId, $schoolYearId);
        }
    }

    private function cancel(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
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

            $active = $this->reservations->activeForStudent($studentId, $schoolYearId);
            if ($active === null || $active['reservation_id'] !== $reservationId) {
                throw new DomainException('Die angegebene Reservierung ist für diesen Schüler nicht aktiv.');
            }

            $this->reservations->cancel($reservationId, $studentId);
            $this->audit->staff($staff, 'booking.reservation.cancelled', 'locker_reservation', $reservationId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $active['locker_id'],
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($studentId, $schoolYearId));
        } catch (DomainException $exception) {
            if ($studentId > 0 && $schoolYearId > 0) {
                return $this->selectedPage($staff, $studentId, $schoolYearId, [$exception->getMessage()], 422);
            }

            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception, $studentId, $schoolYearId);
        }
    }

    /** @param list<string> $errors */
    private function selectedPage(
        AuthenticatedStaff $staff,
        int $studentId,
        int $schoolYearId,
        array $errors = [],
        int $status = 200,
    ): Response {
        $student = $this->recommendations->student($studentId);
        $projectedGrade = $this->recommendations->projectedGradeForStudent($studentId, $schoolYearId);
        $activeReservation = $this->reservations->activeForStudent($studentId, $schoolYearId);
        $available = $this->recommendations->availableForStudent($studentId, $schoolYearId, $projectedGrade, true);
        $recommended = (new LockerRecommendationRanker())->recommend($available, $this->recommendationCount);

        return $this->page(
            $staff,
            $errors,
            $status,
            $studentId,
            $schoolYearId,
            $student,
            $projectedGrade,
            $activeReservation,
            $recommended,
            $available,
        );
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $student
     * @param array<string, mixed>|null $activeReservation
     * @param list<array<string, mixed>> $recommended
     * @param list<array<string, mixed>> $available
     */
    private function page(
        AuthenticatedStaff $staff,
        array $errors = [],
        int $status = 200,
        ?int $selectedStudentId = null,
        ?int $selectedSchoolYearId = null,
        ?array $student = null,
        ?int $projectedGrade = null,
        ?array $activeReservation = null,
        array $recommended = [],
        array $available = [],
    ): Response {
        return Response::html($this->views->render('booking-selection.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'students' => $this->recommendations->activeStudents(),
            'schoolYears' => $this->schoolYears->all(),
            'selectedStudentId' => $selectedStudentId,
            'selectedSchoolYearId' => $selectedSchoolYearId,
            'selectedStudent' => $student,
            'projectedGrade' => $projectedGrade,
            'activeReservation' => $activeReservation,
            'recommended' => $recommended,
            'available' => $available,
        ]), $status);
    }

    private function administrator(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>403</h1><p>Diese Funktion ist nur für Administratoren verfügbar.</p>', 403);
        }

        return $staff;
    }

    private function failure(
        AuthenticatedStaff $staff,
        Throwable $exception,
        int $studentId,
        int $schoolYearId,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Booking selection administration failed', [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'student_id' => $studentId > 0 ? $studentId : null,
            'school_year_id' => $schoolYearId > 0 ? $schoolYearId : null,
            'exception' => $exception,
        ]);
        $errors = ['Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId];

        if ($studentId > 0 && $schoolYearId > 0) {
            try {
                return $this->selectedPage($staff, $studentId, $schoolYearId, $errors, 500);
            } catch (Throwable) {
                // Fall through to the generic page if the selected state can no longer be loaded.
            }
        }

        return $this->page($staff, $errors, 500);
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!preg_match('/^\d+$/', trim($value)) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function selectionUrl(int $studentId, int $schoolYearId): string
    {
        return '/admin/booking-selection?student_id=' . $studentId . '&school_year_id=' . $schoolYearId;
    }
}
