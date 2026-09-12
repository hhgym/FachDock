<?php

declare(strict_types=1);

namespace FachDock\Identity;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Privacy\AccountLifecycleService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class PersonAccountAdminController
{
    public function __construct(
        private readonly PersonAccountAdminService $accounts,
        private readonly AccountLifecycleService $lifecycle,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/student-data', fn (Request $request): Response => $this->studentData($request));
        $router->get('/admin/accounts', fn (Request $request): Response => $this->accountIndex($request));
        $router->post('/admin/accounts/student/deactivate', fn (Request $request): Response => $this->deactivateStudent($request));
        $router->post('/admin/accounts/student/reactivate', fn (Request $request): Response => $this->reactivateStudent($request));
        $router->post('/admin/accounts/student/anonymize', fn (Request $request): Response => $this->anonymizeStudent($request));
        $router->post('/admin/accounts/parent/deactivate', fn (Request $request): Response => $this->deactivateParent($request));
        $router->post('/admin/accounts/parent/reactivate', fn (Request $request): Response => $this->reactivateParent($request));
        $router->post('/admin/accounts/parent/anonymize', fn (Request $request): Response => $this->anonymizeParent($request));
        $router->post('/admin/accounts/lifecycle/run', fn (Request $request): Response => $this->runLifecycle($request));
    }

    private function studentData(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        $search = $this->queryString($request, 'search');
        $status = $this->allowedStatus($this->queryString($request, 'status'), ['active', 'inactive', 'anonymized']);

        return Response::html($this->views->render('student-data.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'students' => $this->accounts->studentData($search, $status),
            'counts' => $this->accounts->counts(),
            'search' => $search,
            'statusFilter' => $status,
        ]));
    }

    private function accountIndex(Request $request, array $errors = [], int $statusCode = 200): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        $type = $this->queryString($request, 'type') === 'parents' ? 'parents' : 'students';
        $search = $this->queryString($request, 'search');
        $status = $this->allowedStatus(
            $this->queryString($request, 'status'),
            ['active', 'waiting', 'deactivated', 'anonymized'],
        );
        $studentId = null;
        $rawStudentId = $this->queryString($request, 'student_id');
        if ($rawStudentId !== '' && ctype_digit($rawStudentId) && (int) $rawStudentId > 0) {
            $studentId = (int) $rawStudentId;
            $type = 'students';
        }
        $saved = $this->queryString($request, 'saved');

        return Response::html($this->views->render('person-accounts.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'type' => $type,
            'search' => $search,
            'statusFilter' => $status,
            'studentId' => $studentId,
            'students' => $type === 'students' ? $this->accounts->studentAccounts($search, $status, $studentId) : [],
            'parents' => $type === 'parents' ? $this->accounts->parentAccounts($search, $status) : [],
            'counts' => $this->accounts->counts(),
            'settings' => $this->lifecycle->settings(),
            'preview' => $this->lifecycle->preview(),
            'errors' => $errors,
            'saved' => $saved,
        ]), $statusCode);
    }

    private function deactivateStudent(Request $request): Response
    {
        return $this->mutation($request, 'students', 'student_deactivated', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->deactivateStudent($id);
            $this->audit->staff($staff, 'student_account.deactivated', 'student', $id);
        });
    }

    private function reactivateStudent(Request $request): Response
    {
        return $this->mutation($request, 'students', 'student_reactivated', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->reactivateStudent($id);
            $this->audit->staff($staff, 'student_account.reactivated', 'student', $id);
        });
    }

    private function anonymizeStudent(Request $request): Response
    {
        return $this->forcedAnonymization($request, 'students', 'student_anonymized', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->anonymizeStudent($id);
            $this->audit->staff($staff, 'student_account.force_anonymized', 'student', $id);
        });
    }

    private function deactivateParent(Request $request): Response
    {
        return $this->mutation($request, 'parents', 'parent_deactivated', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->deactivateParent($id);
            $this->audit->staff($staff, 'parent_account.deactivated', 'parent_contact', $id);
        });
    }

    private function reactivateParent(Request $request): Response
    {
        return $this->mutation($request, 'parents', 'parent_reactivated', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->reactivateParent($id);
            $this->audit->staff($staff, 'parent_account.reactivated', 'parent_contact', $id);
        });
    }

    private function anonymizeParent(Request $request): Response
    {
        return $this->forcedAnonymization($request, 'parents', 'parent_anonymized', function (AuthenticatedStaff $staff, int $id): void {
            $this->lifecycle->anonymizeParent($id);
            $this->audit->staff($staff, 'parent_account.force_anonymized', 'parent_contact', $id);
        });
    }

    private function runLifecycle(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->accountIndex($request, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }
        try {
            $result = $this->lifecycle->process();
            $this->audit->staff($staff, 'account_lifecycle.run', 'privacy', null, $result);
            $this->csrf->rotate();

            return Response::redirect('/admin/accounts?saved=lifecycle_run');
        } catch (Throwable $exception) {
            return $this->failure($request, $staff, $exception);
        }
    }

    /** @param callable(AuthenticatedStaff,int):void $operation */
    private function mutation(Request $request, string $type, string $saved, callable $operation): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->accountIndex($request, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }
        try {
            $id = $this->positiveInt($request->postString('account_id'));
            $operation($staff, $id);
            $this->csrf->rotate();

            return Response::redirect('/admin/accounts?type=' . rawurlencode($type) . '&saved=' . rawurlencode($saved));
        } catch (DomainException $exception) {
            return $this->accountIndex($request, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($request, $staff, $exception);
        }
    }

    /** @param callable(AuthenticatedStaff,int):void $operation */
    private function forcedAnonymization(Request $request, string $type, string $saved, callable $operation): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->accountIndex($request, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
        }
        try {
            $id = $this->positiveInt($request->postString('account_id'));
            $expected = 'ANONYMISIEREN ' . $id;
            if (trim($request->postString('confirmation')) !== $expected) {
                throw new DomainException('Zur sofortigen Anonymisierung muss exakt „' . $expected . '“ eingegeben werden.');
            }
            $operation($staff, $id);
            $this->csrf->rotate();

            return Response::redirect('/admin/accounts?type=' . rawurlencode($type) . '&saved=' . rawurlencode($saved));
        } catch (DomainException $exception) {
            return $this->accountIndex($request, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($request, $staff, $exception);
        }
    }

    private function failure(Request $request, AuthenticatedStaff $staff, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Person account administration failed', [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'exception' => $exception,
        ]);

        return $this->accountIndex(
            $request,
            ['Die Kontoänderung konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
            500,
        );
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

    private function positiveInt(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new DomainException('Das Benutzerkonto ist ungültig.');
        }

        return (int) $value;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param list<string> $allowed */
    private function allowedStatus(string $status, array $allowed): string
    {
        return in_array($status, $allowed, true) ? $status : '';
    }
}
