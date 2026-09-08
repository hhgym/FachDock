<?php

declare(strict_types=1);

namespace FachDock\SchoolYear;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class SchoolYearAdminController
{
    public function __construct(
        private readonly SchoolYearService $schoolYears,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/school-years', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/school-years/ensure', fn (Request $request): Response => $this->ensure($request));
        $router->post('/admin/school-years/create', fn (Request $request): Response => $this->create($request));
        $router->post('/admin/school-years/update', fn (Request $request): Response => $this->update($request));
        $router->post('/admin/school-years/close', fn (Request $request): Response => $this->close($request));
        $router->post('/admin/school-years/reopen', fn (Request $request): Response => $this->reopen($request));
        $router->post('/admin/school-years/reopen/end', fn (Request $request): Response => $this->endReopen($request));
    }

    private function index(Request $request): Response
    {
        unset($request);
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page($staff);
    }

    private function ensure(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff): void {
            $createdIds = $this->schoolYears->ensureCurrentAndNext();
            foreach ($createdIds as $id) {
                $this->audit->staff($staff, 'school_year.auto_created', 'school_year', $id);
            }
        });
    }

    private function create(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $startYear = $this->int($request->postString('start_year'), 'Startjahr', 2000, 9998);
            $annualFeeCents = $this->moneyToCents($request->postString('annual_fee', '0'));
            $maxChanges = $this->int($request->postString('max_parent_changes', '2'), 'Wechselgrenze', 0, 100);
            $id = $this->schoolYears->create($startYear, $annualFeeCents, $maxChanges);
            $this->audit->staff($staff, 'school_year.created', 'school_year', $id, [
                'start_year' => $startYear,
                'annual_fee_cents' => $annualFeeCents,
                'max_parent_changes' => $maxChanges,
            ]);
        });
    }

    private function update(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $before = $this->schoolYears->find($id);
            if ($before === null) {
                throw new DomainException('Das Schuljahr existiert nicht.');
            }

            $this->schoolYears->updateSettings(
                $id,
                $request->postString('new_booking_opens_on'),
                $this->moneyToCents($request->postString('annual_fee', '0')),
                $this->int($request->postString('max_parent_changes', '2'), 'Wechselgrenze', 0, 100),
            );
            $after = $this->schoolYears->find($id);
            $this->audit->staff($staff, 'school_year.settings_changed', 'school_year', $id, [
                'before' => $this->businessSnapshot($before),
                'after' => $after === null ? null : $this->businessSnapshot($after),
            ]);
        });
    }

    private function close(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $reason = trim($request->postString('reason'));
            if ($reason === '') {
                throw new DomainException('Zum Schließen des Schuljahres ist eine Begründung erforderlich.');
            }

            $before = $this->schoolYears->find($id);
            $this->schoolYears->close($id);
            $this->audit->staff($staff, 'school_year.closed', 'school_year', $id, [
                'reason' => $reason,
                'previous_status' => $before['status'] ?? null,
            ]);
        });
    }

    private function reopen(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $minutes = $this->int($request->postString('minutes', '60'), 'Korrekturzeit', 5, 1440);
            $reason = $request->postString('reason');
            $this->schoolYears->reopenForCorrection($id, $minutes, $reason, $staff->id);
            $this->audit->staff($staff, 'school_year.reopened_for_correction', 'school_year', $id, [
                'minutes' => $minutes,
                'reason' => trim($reason),
            ]);
        });
    }

    private function endReopen(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $this->schoolYears->endCorrectionReopen($id);
            $this->audit->staff($staff, 'school_year.correction_reopen_ended', 'school_year', $id);
        });
    }

    /** @param callable(AuthenticatedStaff): void $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }

        try {
            $operation($staff);
            $this->csrf->rotate();

            return Response::redirect('/admin/school-years');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('School year administration failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return $this->page(
                $staff,
                ['Die Änderung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                500,
            );
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

    /** @param list<string> $errors */
    private function page(AuthenticatedStaff $staff, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->views->render('school-years.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'schoolYears' => $this->schoolYears->all(),
        ]), $status);
    }

    private function positiveInt(string $value, string $label): int
    {
        return $this->int($value, $label, 1, PHP_INT_MAX);
    }

    private function int(string $value, string $label, int $minimum, int $maximum): int
    {
        if (!preg_match('/^\d+$/', trim($value))) {
            throw new DomainException($label . ' ist ungültig.');
        }
        $number = (int) trim($value);
        if ($number < $minimum || $number > $maximum) {
            throw new DomainException($label . ' liegt außerhalb des zulässigen Bereichs.');
        }

        return $number;
    }

    private function moneyToCents(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new DomainException('Der Jahresbeitrag ist ungültig.');
        }

        $euros = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ($euros * 100) + (int) $fraction;
        if ($cents > 100000000) {
            throw new DomainException('Der Jahresbeitrag ist zu hoch.');
        }

        return $cents;
    }

    /**
     * @param array<string, mixed> $year
     * @return array<string, int|string>
     */
    private function businessSnapshot(array $year): array
    {
        return [
            'new_booking_opens_on' => (string) $year['new_booking_opens_on'],
            'annual_fee_cents' => (int) $year['annual_fee_cents'],
            'max_parent_changes' => (int) $year['max_parent_changes'],
        ];
    }
}
