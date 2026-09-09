<?php

declare(strict_types=1);

namespace FachDock\FloorPlan;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Parent\AuthenticatedParent;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class FloorPlanController
{
    public function __construct(
        private readonly FloorPlanService $plans,
        private readonly StaffSessionService $staffSessions,
        private readonly ParentSessionService $parentSessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function adminIndex(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page($request, $staff, null, $staff->isAdministrator());
    }

    public function parentIndex(Request $request): Response
    {
        $parent = $this->parent();
        if ($parent instanceof Response) {
            return $parent;
        }

        return $this->page($request, null, $parent, false);
    }

    public function upload(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $floorId = $this->positiveInt($request->postString('floor_id'), 'Etage');
            /** @var array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $file */
            $file = isset($_FILES['floor_plan']) && is_array($_FILES['floor_plan']) ? $_FILES['floor_plan'] : [];
            $planId = $this->plans->createFromUpload(
                $floorId,
                $request->postString('title'),
                $file,
                $staff->id,
            );
            $this->audit->staff($staff, 'floorplan.created', 'floor_plan', $planId, ['floor_id' => $floorId]);
            $this->csrf->rotate();

            return Response::redirect('/admin/floorplans?floor_id=' . $floorId . '&plan_id=' . $planId);
        } catch (DomainException $exception) {
            return $this->adminFailure($request, $staff, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    public function placement(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $planId = $this->positiveInt($request->postString('plan_id'), 'Lageplan');
            $groupId = $this->positiveInt($request->postString('cabinet_group_id'), 'Schrankgruppe');
            $this->plans->setPlacement(
                $planId,
                $groupId,
                $this->decimal($request->postString('x_percent'), 'X-Position'),
                $this->decimal($request->postString('y_percent'), 'Y-Position'),
                $this->decimal($request->postString('width_percent'), 'Breite'),
                $this->decimal($request->postString('height_percent'), 'Höhe'),
                $staff->id,
            );
            $this->audit->staff($staff, 'floorplan.group_placed', 'cabinet_group', $groupId, ['plan_id' => $planId]);
            $this->csrf->rotate();

            return Response::redirect($this->planUrl($request, $planId));
        } catch (DomainException $exception) {
            return $this->adminFailure($request, $staff, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    public function removePlacement(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $planId = $this->positiveInt($request->postString('plan_id'), 'Lageplan');
            $groupId = $this->positiveInt($request->postString('cabinet_group_id'), 'Schrankgruppe');
            $this->plans->removePlacement($planId, $groupId);
            $this->audit->staff($staff, 'floorplan.group_removed', 'cabinet_group', $groupId, ['plan_id' => $planId]);
            $this->csrf->rotate();

            return Response::redirect($this->planUrl($request, $planId));
        } catch (DomainException $exception) {
            return $this->adminFailure($request, $staff, $exception->getMessage(), 422);
        }
    }

    public function delete(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $planId = $this->positiveInt($request->postString('plan_id'), 'Lageplan');
            $floorId = $this->optionalPositiveInt($request->postString('floor_id'));
            $this->plans->deletePlan($planId);
            $this->audit->staff($staff, 'floorplan.deleted', 'floor_plan', $planId);
            $this->csrf->rotate();

            return Response::redirect('/admin/floorplans' . ($floorId !== null ? '?floor_id=' . $floorId : ''));
        } catch (DomainException $exception) {
            return $this->adminFailure($request, $staff, $exception->getMessage(), 422);
        }
    }

    public function image(Request $request): Response
    {
        if ($this->staffSessions->current() === null && $this->parentSessions->current() === null) {
            return Response::html('<h1>401</h1><p>Anmeldung erforderlich.</p>', 401);
        }
        $planId = $this->optionalPositiveInt($this->queryString($request, 'id'));
        if ($planId === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        $image = $this->plans->image($planId);
        if ($image === null) {
            return Response::html('<h1>404</h1>', 404);
        }
        $body = file_get_contents($image['path']);
        if (!is_string($body)) {
            return Response::html('<h1>404</h1>', 404);
        }

        return new Response($body, 200, [
            'Content-Type' => $image['mime_type'],
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** @param list<string> $errors */
    private function page(
        Request $request,
        ?AuthenticatedStaff $staff,
        ?AuthenticatedParent $parent,
        bool $editable,
        array $errors = [],
        int $status = 200,
    ): Response {
        $floors = $this->plans->floors();
        $floorId = $this->optionalPositiveInt($this->queryString($request, 'floor_id'));
        if ($floorId === null && $floors !== []) {
            $floorId = $floors[0]['id'];
        }
        $floorPlans = $floorId !== null ? $this->plans->plansForFloor($floorId) : [];
        $planId = $this->optionalPositiveInt($this->queryString($request, 'plan_id'));
        if ($planId === null && $floorPlans !== []) {
            $planId = $floorPlans[0]['id'];
        }
        $schoolYearId = $this->optionalPositiveInt($this->queryString($request, 'school_year_id'))
            ?? $this->plans->defaultSchoolYearId();
        $plan = null;
        if ($planId !== null) {
            try {
                $plan = $this->plans->plan($planId, $schoolYearId);
                $floorId = (int) $plan['floor_id'];
                $floorPlans = $this->plans->plansForFloor($floorId);
            } catch (DomainException $exception) {
                $errors[] = $exception->getMessage();
                $status = max($status, 422);
            }
        }

        return Response::html($this->views->render('floorplans.php', [
            'staff' => $staff,
            'parent' => $parent,
            'editable' => $editable,
            'floors' => $floors,
            'floorPlans' => $floorPlans,
            'selectedFloorId' => $floorId,
            'selectedPlanId' => $planId,
            'schoolYears' => $this->plans->schoolYears(),
            'selectedSchoolYearId' => $schoolYearId,
            'plan' => $plan,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    private function adminFailure(Request $request, AuthenticatedStaff $staff, string $message, int $status): Response
    {
        return $this->page($request, $staff, null, true, [$message], $status);
    }

    private function technicalFailure(Request $request, AuthenticatedStaff $staff, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Floor plan operation failed', ['error_id' => $errorId, 'exception' => $exception]);

        return $this->adminFailure(
            $request,
            $staff,
            'Die Lageplan-Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId,
            500,
        );
    }

    private function staff(): AuthenticatedStaff|Response
    {
        $staff = $this->staffSessions->current();

        return $staff ?? Response::redirect('/login');
    }

    private function administrator(): AuthenticatedStaff|Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>403</h1><p>Administratorrechte erforderlich.</p>', 403);
        }

        return $staff;
    }

    private function parent(): AuthenticatedParent|Response
    {
        $parent = $this->parentSessions->current();

        return $parent ?? Response::redirect('/parent/login');
    }

    private function planUrl(Request $request, int $planId): string
    {
        $schoolYearId = $this->optionalPositiveInt($request->postString('school_year_id'));
        $url = '/admin/floorplans?plan_id=' . $planId;
        if ($schoolYearId !== null) {
            $url .= '&school_year_id=' . $schoolYearId;
        }

        return $url;
    }

    private function positiveInt(string $value, string $label): int
    {
        $parsed = $this->optionalPositiveInt($value);
        if ($parsed === null) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return $parsed;
    }

    private function optionalPositiveInt(string $value): ?int
    {
        return preg_match('/^\d+$/', trim($value)) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    private function decimal(string $value, string $label): float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (float) $value;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
