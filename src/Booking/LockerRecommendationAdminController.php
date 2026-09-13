<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\SchoolYear\SchoolYearService;
use FachDock\View\ViewRenderer;

final class LockerRecommendationAdminController
{
    public function __construct(
        private readonly LockerRecommendationService $recommendations,
        private readonly SchoolYearService $schoolYears,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly int $defaultRecommendationCount = 3,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/recommendations', fn (Request $request): Response => $this->index($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        $studentValue = $this->queryString($request, 'student_id');
        $yearValue = $this->queryString($request, 'school_year_id');
        $defaultSchoolYearId = $this->defaultSchoolYearId();
        if ($studentValue === '') {
            return $this->page($staff, [], 200, null, $yearValue !== ''
                ? $this->positiveInt($yearValue, 'Schuljahr')
                : $defaultSchoolYearId);
        }

        try {
            $studentId = $this->positiveInt($studentValue, 'Schüler');
            $schoolYearId = $yearValue !== ''
                ? $this->positiveInt($yearValue, 'Schuljahr')
                : $defaultSchoolYearId;
            if ($schoolYearId === null) {
                throw new DomainException('Es ist kein auswählbares Schuljahr vorhanden.');
            }
            $student = $this->recommendations->student($studentId);
            $available = $this->recommendations->availableForStudent(
                $studentId,
                $schoolYearId,
                null,
                true,
            );
            $recommended = $this->recommendations->recommendForStudent(
                $studentId,
                $schoolYearId,
                null,
                $this->defaultRecommendationCount,
                true,
            );

            return $this->page(
                $staff,
                [],
                200,
                $studentId,
                $schoolYearId,
                $student,
                $recommended,
                $available,
            );
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422, null, $defaultSchoolYearId);
        }
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

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $student
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
        array $recommended = [],
        array $available = [],
    ): Response {
        return Response::html($this->views->render('recommendations.php', [
            'staff' => $staff,
            'errors' => $errors,
            'students' => $this->recommendations->activeStudents(),
            'schoolYears' => $this->schoolYears->all(),
            'selectedStudentId' => $selectedStudentId,
            'selectedSchoolYearId' => $selectedSchoolYearId,
            'selectedStudent' => $student,
            'recommended' => $recommended,
            'available' => $available,
        ]), $status);
    }

    private function defaultSchoolYearId(): ?int
    {
        $years = $this->schoolYears->all();
        foreach ($years as $year) {
            if ((string) $year['status'] === 'current') {
                return (int) $year['id'];
            }
        }
        foreach ($years as $year) {
            if ((string) $year['status'] !== 'closed') {
                return (int) $year['id'];
            }
        }

        return null;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!preg_match('/^\d+$/', $value) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }
}
