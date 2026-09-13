<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\FloorPlan\FloorPlanService;
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
    private const BASE_PATH = '/admin/lockers';

    private ?FloorPlanService $resolvedFloorPlans = null;

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
        private readonly ?FloorPlanService $floorPlans = null,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get(self::BASE_PATH, fn (Request $request): Response => $this->index($request));
        $router->post(self::BASE_PATH . '/reserve', fn (Request $request): Response => $this->reserve($request));
        $router->post(self::BASE_PATH . '/assign', fn (Request $request): Response => $this->assign($request));
        $router->post(self::BASE_PATH . '/reservation/cancel', fn (Request $request): Response => $this->cancel($request));

        $router->get('/admin/booking-selection', fn (Request $request): Response => $this->legacyRedirect($request));
        $router->post('/admin/booking-selection/reserve', fn (Request $request): Response => $this->reserve($request));
        $router->post('/admin/booking-selection/assign', fn (Request $request): Response => $this->assign($request));
        $router->post('/admin/booking-selection/cancel', fn (Request $request): Response => $this->cancel($request));
    }

    private function legacyRedirect(Request $request): Response
    {
        $query = $request->query();
        $suffix = $query === [] ? '' : '?' . http_build_query($query);

        return Response::redirect(self::BASE_PATH . $suffix);
    }

    private function index(Request $request): Response
    {
        $staff = $this->staffMember();
        if ($staff instanceof Response) {
            return $staff;
        }

        try {
            $yearValue = $this->queryString($request, 'school_year_id');
            $studentValue = $this->queryString($request, 'student_id');
            $schoolYearId = $yearValue !== ''
                ? $this->positiveInt($yearValue, 'Schuljahr')
                : $this->defaultSchoolYearId();
            $studentId = $studentValue !== '' ? $this->positiveInt($studentValue, 'Schüler') : null;
            $floorId = $this->optionalPositiveInt($this->queryString($request, 'floor_id'), 'Etage');
            $planId = $this->optionalPositiveInt($this->queryString($request, 'plan_id'), 'Lageplan');
            $groupId = $this->optionalPositiveInt($this->queryString($request, 'group_id'), 'Schrankgruppe');

            if ($schoolYearId === null) {
                return $this->page($staff);
            }

            return $this->managementPage(
                $staff,
                $schoolYearId,
                $studentId,
                [],
                200,
                $floorId,
                $planId,
                $groupId,
            );
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        }
    }

    private function reserve(Request $request): Response
    {
        $staff = $this->staffMember();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $studentId = 0;
        $schoolYearId = 0;
        $floorId = null;
        $planId = null;
        $groupId = null;
        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $schoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $lockerId = $this->positiveInt($request->postString('locker_id'), 'Schließfach');
            $floorId = $this->optionalPositiveInt($request->postString('floor_id'), 'Etage');
            $planId = $this->optionalPositiveInt($request->postString('plan_id'), 'Lageplan');
            $groupId = $this->optionalPositiveInt($request->postString('group_id'), 'Schrankgruppe');

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
                'source' => 'admin_locker_management',
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl(
                $studentId,
                $schoolYearId,
                'reserved',
                $floorId,
                $planId,
                $groupId,
            ));
        } catch (DomainException $exception) {
            return $this->actionError(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        } catch (Throwable $exception) {
            return $this->failure(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        }
    }

    private function assign(Request $request): Response
    {
        $staff = $this->staffMember();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $studentId = 0;
        $schoolYearId = 0;
        $floorId = null;
        $planId = null;
        $groupId = null;
        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $schoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $lockerId = $this->positiveInt($request->postString('locker_id'), 'Schließfach');
            $floorId = $this->optionalPositiveInt($request->postString('floor_id'), 'Etage');
            $planId = $this->optionalPositiveInt($request->postString('plan_id'), 'Lageplan');
            $groupId = $this->optionalPositiveInt($request->postString('group_id'), 'Schrankgruppe');
            $bookingId = $this->reservations->assignWithoutPayment(
                $studentId,
                $schoolYearId,
                $lockerId,
                $staff->id,
            );

            $this->audit->staff($staff, 'booking.admin_assigned', 'booking', $bookingId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $lockerId,
                'charged_fee_cents' => 0,
                'fee_exemption_type' => 'administrative_assignment',
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl(
                $studentId,
                $schoolYearId,
                'assigned',
                $floorId,
                $planId,
                $groupId,
            ));
        } catch (DomainException $exception) {
            return $this->actionError(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        } catch (Throwable $exception) {
            return $this->failure(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        }
    }

    private function cancel(Request $request): Response
    {
        $staff = $this->staffMember();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $studentId = 0;
        $schoolYearId = 0;
        $floorId = null;
        $planId = null;
        $groupId = null;
        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $schoolYearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $reservationId = $this->positiveInt($request->postString('reservation_id'), 'Reservierung');
            $floorId = $this->optionalPositiveInt($request->postString('floor_id'), 'Etage');
            $planId = $this->optionalPositiveInt($request->postString('plan_id'), 'Lageplan');
            $groupId = $this->optionalPositiveInt($request->postString('group_id'), 'Schrankgruppe');

            $active = $this->reservations->activeForStudent($studentId, $schoolYearId);
            if ($active === null || $active['reservation_id'] !== $reservationId) {
                throw new DomainException('Die angegebene Reservierung ist für diesen Schüler nicht aktiv.');
            }

            $this->reservations->cancel($reservationId, $studentId);
            $this->audit->staff($staff, 'booking.reservation.cancelled', 'locker_reservation', $reservationId, [
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $active['locker_id'],
                'source' => 'admin_locker_management',
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl(
                $studentId,
                $schoolYearId,
                'cancelled',
                $floorId,
                $planId,
                $groupId,
            ));
        } catch (DomainException $exception) {
            return $this->actionError(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        } catch (Throwable $exception) {
            return $this->failure(
                $staff,
                $exception,
                $studentId,
                $schoolYearId,
                $floorId,
                $planId,
                $groupId,
            );
        }
    }

    /** @param list<string> $errors */
    private function managementPage(
        AuthenticatedStaff $staff,
        int $schoolYearId,
        ?int $studentId = null,
        array $errors = [],
        int $status = 200,
        ?int $floorId = null,
        ?int $planId = null,
        ?int $groupId = null,
    ): Response {
        $this->reservations->expireStale();
        $overview = $this->recommendations->lockerOverview($schoolYearId);
        $student = null;
        $projectedGrade = null;
        $activeReservation = null;
        $currentBooking = null;
        $selectionBlockedReason = null;
        $eligibleLockerIds = [];
        $recommendedLockerIds = [];
        $scores = [];

        if ($studentId !== null) {
            $student = $this->recommendations->student($studentId);
            $projectedGrade = $this->recommendations->projectedGradeForStudent($studentId, $schoolYearId);
            $activeReservation = $this->reservations->activeForStudent($studentId, $schoolYearId);
            foreach ($overview as $locker) {
                if ((int) ($locker['occupied_student_id'] ?? 0) === $studentId) {
                    $currentBooking = $locker;
                    break;
                }
            }

            if ($currentBooking !== null) {
                $selectionBlockedReason = 'Für diesen Schüler besteht in diesem Schuljahr bereits eine aktive Buchung.';
            } elseif ($activeReservation !== null && (string) $activeReservation['status'] === 'payment_running') {
                $selectionBlockedReason = 'Für diesen Schüler läuft bereits ein Zahlungsvorgang. Änderungen sind bis zum Abschluss nur in der Zahlungsverwaltung möglich.';
            } else {
                try {
                    $available = $this->recommendations->availableForStudent(
                        $studentId,
                        $schoolYearId,
                        $projectedGrade,
                        true,
                    );
                    foreach ($available as $locker) {
                        $lockerId = (int) $locker['locker_id'];
                        $eligibleLockerIds[$lockerId] = true;
                        $scores[$lockerId] = (int) $locker['score'];
                    }
                    $recommended = (new LockerRecommendationRanker())->recommend(
                        $available,
                        $this->recommendationCount,
                    );
                    foreach ($recommended as $locker) {
                        $recommendedLockerIds[(int) $locker['locker_id']] = true;
                    }
                } catch (DomainException $exception) {
                    $selectionBlockedReason = $exception->getMessage();
                }
            }
        }

        $counts = ['free' => 0, 'reserved' => 0, 'occupied' => 0, 'issue' => 0, 'unavailable' => 0];
        foreach ($overview as $locker) {
            $availability = (string) ($locker['availability_status'] ?? 'unavailable');
            if (isset($counts[$availability])) {
                ++$counts[$availability];
            }
        }

        $locationContext = $this->locationContext($schoolYearId, $overview, $floorId, $planId, $groupId);

        return $this->page(
            $staff,
            $errors,
            $status,
            $studentId,
            $schoolYearId,
            $student,
            $projectedGrade,
            $activeReservation,
            $currentBooking,
            $selectionBlockedReason,
            $overview,
            $eligibleLockerIds,
            $recommendedLockerIds,
            $scores,
            $counts,
            $locationContext,
        );
    }

    /**
     * @param list<array<string, mixed>> $overview
     * @return array<string, mixed>
     */
    private function locationContext(
        int $schoolYearId,
        array $overview,
        ?int $floorId,
        ?int $planId,
        ?int $groupId,
    ): array {
        $service = $this->floorPlanService();
        $floors = array_values(array_filter(
            $service->floors(),
            static fn (array $floor): bool => (int) $floor['plan_count'] > 0,
        ));

        $empty = [
            'floors' => $floors,
            'floor_plans' => [],
            'selected_floor_id' => null,
            'selected_plan_id' => null,
            'plan' => null,
            'selected_group_id' => null,
            'selected_group' => null,
            'selected_group_overview' => [],
        ];
        if ($floorId === null) {
            return $empty;
        }

        $floorKnown = false;
        foreach ($floors as $floor) {
            if ((int) $floor['id'] === $floorId) {
                $floorKnown = true;
                break;
            }
        }
        if (!$floorKnown) {
            throw new DomainException('Die ausgewählte Etage hat keinen aktiven Lageplan.');
        }

        $plans = $service->plansForFloor($floorId);
        if ($plans === []) {
            return array_replace($empty, ['selected_floor_id' => $floorId]);
        }

        $selectedPlanId = null;
        if ($planId !== null) {
            foreach ($plans as $candidate) {
                if ((int) $candidate['id'] === $planId) {
                    $selectedPlanId = $planId;
                    break;
                }
            }
        }
        $selectedPlanId ??= (int) $plans[0]['id'];
        $plan = $service->plan($selectedPlanId, $schoolYearId);

        $countsByGroup = [];
        foreach ($overview as $locker) {
            $currentGroupId = (int) ($locker['cabinet_group_id'] ?? 0);
            if ($currentGroupId < 1) {
                continue;
            }
            $countsByGroup[$currentGroupId] ??= [
                'free' => 0,
                'reserved' => 0,
                'occupied' => 0,
                'issue' => 0,
                'unavailable' => 0,
            ];
            $availability = (string) ($locker['availability_status'] ?? 'unavailable');
            if (isset($countsByGroup[$currentGroupId][$availability])) {
                ++$countsByGroup[$currentGroupId][$availability];
            }
        }

        $selectedGroup = null;
        foreach ($plan['groups'] as $index => $group) {
            if (!is_array($group)) {
                continue;
            }
            $currentGroupId = (int) ($group['id'] ?? 0);
            $group['admin_counts'] = $countsByGroup[$currentGroupId] ?? [
                'free' => 0,
                'reserved' => 0,
                'occupied' => 0,
                'issue' => 0,
                'unavailable' => 0,
            ];
            $plan['groups'][$index] = $group;
            if ($groupId !== null && $currentGroupId === $groupId) {
                $selectedGroup = $group;
            }
        }

        if ($groupId !== null && $selectedGroup === null) {
            throw new DomainException('Die ausgewählte Schrankgruppe gehört nicht zu dieser Etage.');
        }

        $selectedGroupOverview = $groupId === null
            ? []
            : array_values(array_filter(
                $overview,
                static fn (array $locker): bool => (int) ($locker['cabinet_group_id'] ?? 0) === $groupId,
            ));

        return [
            'floors' => $floors,
            'floor_plans' => $plans,
            'selected_floor_id' => $floorId,
            'selected_plan_id' => $selectedPlanId,
            'plan' => $plan,
            'selected_group_id' => $groupId,
            'selected_group' => $selectedGroup,
            'selected_group_overview' => $selectedGroupOverview,
        ];
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed>|null $student
     * @param array<string, mixed>|null $activeReservation
     * @param array<string, mixed>|null $currentBooking
     * @param list<array<string, mixed>> $lockerOverview
     * @param array<int, bool> $eligibleLockerIds
     * @param array<int, bool> $recommendedLockerIds
     * @param array<int, int> $scores
     * @param array{free:int,reserved:int,occupied:int,issue:int,unavailable:int} $counts
     * @param array<string, mixed> $locationContext
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
        ?array $currentBooking = null,
        ?string $selectionBlockedReason = null,
        array $lockerOverview = [],
        array $eligibleLockerIds = [],
        array $recommendedLockerIds = [],
        array $scores = [],
        array $counts = ['free' => 0, 'reserved' => 0, 'occupied' => 0, 'issue' => 0, 'unavailable' => 0],
        array $locationContext = [],
    ): Response {
        return Response::html($this->views->render('locker-management.php', [
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
            'currentBooking' => $currentBooking,
            'selectionBlockedReason' => $selectionBlockedReason,
            'lockerOverview' => $lockerOverview,
            'eligibleLockerIds' => $eligibleLockerIds,
            'recommendedLockerIds' => $recommendedLockerIds,
            'scores' => $scores,
            'counts' => $counts,
            'locationContext' => $locationContext,
        ]), $status);
    }

    private function staffMember(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }

        return $staff;
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

    private function actionError(
        AuthenticatedStaff $staff,
        DomainException $exception,
        int $studentId,
        int $schoolYearId,
        ?int $floorId,
        ?int $planId,
        ?int $groupId,
    ): Response {
        if ($schoolYearId > 0) {
            return $this->managementPage(
                $staff,
                $schoolYearId,
                $studentId > 0 ? $studentId : null,
                [$exception->getMessage()],
                422,
                $floorId,
                $planId,
                $groupId,
            );
        }

        return $this->page($staff, [$exception->getMessage()], 422);
    }

    private function failure(
        AuthenticatedStaff $staff,
        Throwable $exception,
        int $studentId,
        int $schoolYearId,
        ?int $floorId,
        ?int $planId,
        ?int $groupId,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Locker management administration failed', [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'student_id' => $studentId > 0 ? $studentId : null,
            'school_year_id' => $schoolYearId > 0 ? $schoolYearId : null,
            'floor_id' => $floorId,
            'plan_id' => $planId,
            'group_id' => $groupId,
            'exception' => $exception,
        ]);
        $errors = ['Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId];

        if ($schoolYearId > 0) {
            try {
                return $this->managementPage(
                    $staff,
                    $schoolYearId,
                    $studentId > 0 ? $studentId : null,
                    $errors,
                    500,
                    $floorId,
                    $planId,
                    $groupId,
                );
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

    private function optionalPositiveInt(string $value, string $label): ?int
    {
        return trim($value) === '' ? null : $this->positiveInt($value, $label);
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!preg_match('/^\d+$/', trim($value)) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function selectionUrl(
        int $studentId,
        int $schoolYearId,
        string $action = '',
        ?int $floorId = null,
        ?int $planId = null,
        ?int $groupId = null,
    ): string {
        $query = [
            'school_year_id' => $schoolYearId,
            'student_id' => $studentId,
        ];
        if ($floorId !== null) {
            $query['floor_id'] = $floorId;
        }
        if ($planId !== null) {
            $query['plan_id'] = $planId;
        }
        if ($groupId !== null) {
            $query['group_id'] = $groupId;
        }
        if ($action !== '') {
            $query['action'] = $action;
        }

        return self::BASE_PATH . '?' . http_build_query($query) . ($groupId !== null ? '#locker-group' : '');
    }

    private function floorPlanService(): FloorPlanService
    {
        if ($this->floorPlans !== null) {
            return $this->floorPlans;
        }
        if ($this->resolvedFloorPlans !== null) {
            return $this->resolvedFloorPlans;
        }

        $root = dirname(__DIR__, 2);
        $this->resolvedFloorPlans = new FloorPlanService(
            ConnectionFactory::fromConfig(Config::load($root)),
            $root,
        );

        return $this->resolvedFloorPlans;
    }
}
