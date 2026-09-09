<?php

declare(strict_types=1);

namespace FachDock\Operations;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Location\LockerOperatingStatus;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class OperationsAdminController
{
    public function __construct(
        private readonly LockerSupportService $support,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page(
            $staff,
            [],
            $this->query($request, 'saved') === '1',
            $this->query($request, 'status'),
            $this->positiveIntOrNull($this->query($request, 'incident_id')),
        );
    }

    public function report(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): string {
            $id = $this->support->reportFromStaff(
                $staff,
                $this->positiveInt($request->postString('locker_id'), 'Schließfach'),
                $request->postString('category'),
                $request->postString('description'),
            );
            $this->audit->staff($staff, 'locker.incident.reported', 'locker_incident', $id, [
                'source' => 'staff',
                'ip_address' => $request->clientIp(),
            ]);

            return '/admin/operations?saved=1&incident_id=' . $id;
        });
    }

    public function updateIncident(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): string {
            $id = $this->positiveInt($request->postString('incident_id'), 'Vorgang');
            $status = $request->postString('status');
            $this->support->updateIncident($staff, $id, $status, $request->postString('note'));
            $this->audit->staff($staff, 'locker.incident.updated', 'locker_incident', $id, [
                'status' => $status,
                'ip_address' => $request->clientIp(),
            ]);

            return '/admin/operations?saved=1&incident_id=' . $id;
        });
    }

    public function emergencyOpening(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): string {
            $id = $this->positiveInt($request->postString('incident_id'), 'Vorgang');
            $this->support->recordEmergencyOpening($staff, $id, $request->postString('note'));
            $this->audit->staff($staff, 'locker.emergency_opening.performed', 'locker_incident', $id, [
                'ip_address' => $request->clientIp(),
            ]);

            return '/admin/operations?saved=1&incident_id=' . $id;
        });
    }

    public function updateLockerStatus(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): string {
            $lockerId = $this->positiveInt($request->postString('locker_id'), 'Schließfach');
            $incidentId = $this->positiveIntOrNull($request->postString('incident_id'));
            $status = $request->postString('operating_status');
            $this->support->updateLockerStatus(
                $staff,
                $lockerId,
                $status,
                $request->postString('bookable') === '1',
                $request->postString('note'),
                $incidentId,
            );
            $this->audit->staff($staff, 'locker.operating_status.updated', 'locker', $lockerId, [
                'operating_status' => $status,
                'incident_id' => $incidentId,
                'ip_address' => $request->clientIp(),
            ]);

            return '/admin/operations?saved=1' . ($incidentId !== null ? '&incident_id=' . $incidentId : '');
        });
    }

    /** @param callable(AuthenticatedStaff): string $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        $staff = $this->staff();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, '', null, 419);
        }

        try {
            $redirect = $operation($staff);
            $this->csrf->rotate();

            return Response::redirect($redirect);
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], false, '', null, 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Locker operations administration failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return $this->page(
                $staff,
                ['Die Änderung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                false,
                '',
                null,
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private function page(
        AuthenticatedStaff $staff,
        array $errors,
        bool $success,
        string $statusFilter,
        ?int $incidentId,
        int $statusCode = 200,
    ): Response {
        try {
            $detail = $incidentId === null ? null : $this->support->incident($incidentId);
            $events = $incidentId === null ? [] : $this->support->events($incidentId);
            $operationHistory = $detail === null
                ? []
                : $this->support->operationHistory((int) $detail['locker_id']);
            $incidents = $this->support->incidentsForStaff($statusFilter !== '' ? $statusFilter : null);
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
            $detail = null;
            $events = [];
            $operationHistory = [];
            $incidents = $this->support->incidentsForStaff();
        }

        return Response::html($this->views->render('operations-admin.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'statusFilter' => $statusFilter,
            'incidents' => $incidents,
            'detail' => $detail,
            'events' => $events,
            'operationHistory' => $operationHistory,
            'lockers' => $this->support->lockersForStaff(),
            'categories' => LockerIncidentCategory::cases(),
            'incidentStatuses' => LockerIncidentStatus::cases(),
            'operatingStatuses' => LockerOperatingStatus::cases(),
        ]), $statusCode);
    }

    private function staff(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }

        return $staff;
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!ctype_digit(trim($value)) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function positiveIntOrNull(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function query(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
