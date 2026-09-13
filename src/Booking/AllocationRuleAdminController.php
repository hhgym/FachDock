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
use FachDock\Location\LocationCatalogService;
use FachDock\SchoolYear\SchoolYearService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class AllocationRuleAdminController
{
    public function __construct(
        private readonly AllocationRuleService $rules,
        private readonly AllocationRuleTestService $ruleTest,
        private readonly LocationCatalogService $locations,
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
        $router->get('/admin/allocation-rules', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/allocation-rules/create', fn (Request $request): Response => $this->create($request));
        $router->post('/admin/allocation-rules/update', fn (Request $request): Response => $this->update($request));
        $router->post('/admin/allocation-rules/override', fn (Request $request): Response => $this->override($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        $yearValue = $this->queryString($request, 'school_year_id');
        $gradeValue = $this->queryString($request, 'grade');
        $defaultSchoolYearId = $this->defaultSchoolYearId();
        if ($gradeValue === '') {
            $yearId = $yearValue !== '' ? $this->positiveInt($yearValue, 'Schuljahr') : $defaultSchoolYearId;

            return $this->page($staff, [], 200, $yearId);
        }

        try {
            $yearId = $yearValue !== '' ? $this->positiveInt($yearValue, 'Schuljahr') : $defaultSchoolYearId;
            if ($yearId === null) {
                throw new DomainException('Es ist kein auswählbares Schuljahr vorhanden.');
            }
            $grade = $this->int($gradeValue, 'Klassenstufe', 5, 12);

            return $this->page($staff, [], 200, $yearId, $grade, $this->ruleTest->test($yearId, $grade));
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422, $defaultSchoolYearId);
        }
    }

    private function create(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            [$scopeType, $scopeId] = $this->scope($request->postString('scope'));
            $kind = $this->kind($request->postString('rule_kind'));
            $id = $this->rules->create(
                $request->postString('name'),
                $kind,
                $this->int($request->postString('min_grade'), 'Klassenstufe von', 5, 12),
                $this->int($request->postString('max_grade'), 'Klassenstufe bis', 5, 12),
                $scopeType,
                $scopeId,
                $this->signedInt($request->postString('weight', '0'), 'Gewichtung', -1000, 1000),
                $this->int($request->postString('priority', '100'), 'Priorität', 1, 10000),
                $this->optionalPositiveInt($request->postString('valid_from_school_year_id')),
                $this->optionalPositiveInt($request->postString('valid_until_school_year_id')),
                $request->postString('notes'),
                $request->postString('active') === '1',
            );
            $this->audit->staff($staff, 'allocation_rule.created', 'allocation_rule', $id, [
                'kind' => $kind->value,
                'scope' => $scopeType . ':' . $scopeId,
            ]);
        });
    }

    private function update(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $id = $this->positiveInt($request->postString('rule_id'), 'Zuteilungsregel');
            [$scopeType, $scopeId] = $this->scope($request->postString('scope'));
            $kind = $this->kind($request->postString('rule_kind'));
            $this->rules->update(
                $id,
                $request->postString('name'),
                $kind,
                $this->int($request->postString('min_grade'), 'Klassenstufe von', 5, 12),
                $this->int($request->postString('max_grade'), 'Klassenstufe bis', 5, 12),
                $scopeType,
                $scopeId,
                $this->signedInt($request->postString('weight', '0'), 'Gewichtung', -1000, 1000),
                $this->int($request->postString('priority', '100'), 'Priorität', 1, 10000),
                $this->optionalPositiveInt($request->postString('valid_from_school_year_id')),
                $this->optionalPositiveInt($request->postString('valid_until_school_year_id')),
                $request->postString('notes'),
                $request->postString('active') === '1',
            );
            $this->audit->staff($staff, 'allocation_rule.updated', 'allocation_rule', $id, [
                'kind' => $kind->value,
                'scope' => $scopeType . ':' . $scopeId,
            ]);
        });
    }

    private function override(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $ruleId = $this->positiveInt($request->postString('rule_id'), 'Zuteilungsregel');
            $yearId = $this->positiveInt($request->postString('school_year_id'), 'Schuljahr');
            $enabled = $request->postString('enabled') === '1';
            $this->rules->setSchoolYearEnabled($ruleId, $yearId, $enabled);
            $this->audit->staff($staff, 'allocation_rule.school_year_override_changed', 'allocation_rule', $ruleId, [
                'school_year_id' => $yearId,
                'enabled' => $enabled,
            ]);
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

            return Response::redirect('/admin/allocation-rules');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Allocation rule administration failed', [
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

    /**
     * @param list<string> $errors
     * @param list<array<string, mixed>> $testResults
     */
    private function page(
        AuthenticatedStaff $staff,
        array $errors = [],
        int $status = 200,
        ?int $testSchoolYearId = null,
        ?int $testGrade = null,
        array $testResults = [],
    ): Response {
        return Response::html($this->views->render('allocation-rules.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'rules' => $this->rules->all(),
            'schoolYears' => $this->schoolYears->all(),
            'buildings' => $this->locations->buildings(),
            'floors' => $this->locations->floors(),
            'areas' => $this->locations->areas(),
            'cabinetGroups' => $this->locations->cabinetGroups(),
            'testSchoolYearId' => $testSchoolYearId,
            'testGrade' => $testGrade,
            'testResults' => $testResults,
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

    private function kind(string $value): AllocationRuleKind
    {
        return AllocationRuleKind::tryFrom($value)
            ?? throw new DomainException('Der Regeltyp ist ungültig.');
    }

    /** @return array{string, int} */
    private function scope(string $value): array
    {
        $parts = explode(':', $value, 2);
        if (count($parts) !== 2) {
            throw new DomainException('Der räumliche Geltungsbereich ist ungültig.');
        }

        return [$parts[0], $this->positiveInt($parts[1], 'Geltungsbereich')];
    }

    private function optionalPositiveInt(string $value): ?int
    {
        return trim($value) === '' ? null : $this->positiveInt($value, 'Schuljahr');
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

    private function signedInt(string $value, string $label, int $minimum, int $maximum): int
    {
        if (!preg_match('/^-?\d+$/', trim($value))) {
            throw new DomainException($label . ' ist ungültig.');
        }
        $number = (int) trim($value);
        if ($number < $minimum || $number > $maximum) {
            throw new DomainException($label . ' liegt außerhalb des zulässigen Bereichs.');
        }

        return $number;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
