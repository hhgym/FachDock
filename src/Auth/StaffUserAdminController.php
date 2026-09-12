<?php

declare(strict_types=1);

namespace FachDock\Auth;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class StaffUserAdminController
{
    public function __construct(
        private readonly StaffUserManagementService $users,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/users', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/users/create', fn (Request $request): Response => $this->create($request));
        $router->post('/admin/users/deactivate', fn (Request $request): Response => $this->deactivate($request));
        $router->post('/admin/users/reactivate', fn (Request $request): Response => $this->reactivate($request));
        $router->post('/admin/users/anonymize', fn (Request $request): Response => $this->anonymize($request));
        $router->post('/admin/users/delete', fn (Request $request): Response => $this->delete($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        $query = $request->query();
        $success = isset($query['saved']) && is_scalar($query['saved']) ? (string) $query['saved'] : '';

        return $this->page($staff, [], [], $success);
    }

    private function create(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], $this->safeForm($request), '', 419);
        }

        try {
            $userId = $this->users->create(
                $request->postString('username'),
                $request->postString('display_name'),
                $request->postString('email'),
                $request->postString('role'),
                $request->postString('password'),
                $request->postString('password_confirmation'),
            );
            $this->audit->staff($staff, 'staff_user.created', 'staff_user', $userId, [
                'role' => $request->postString('role'),
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/users?saved=created');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], $this->safeForm($request), '', 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception, $this->safeForm($request));
        }
    }

    private function deactivate(Request $request): Response
    {
        return $this->lifecycleMutation($request, 'deactivated', function (AuthenticatedStaff $staff, int $userId): void {
            $this->users->deactivate($userId, $staff->id);
            $this->audit->staff($staff, 'staff_user.deactivated', 'staff_user', $userId);
        });
    }

    private function reactivate(Request $request): Response
    {
        return $this->lifecycleMutation($request, 'reactivated', function (AuthenticatedStaff $staff, int $userId): void {
            $this->users->reactivate($userId);
            $this->audit->staff($staff, 'staff_user.reactivated', 'staff_user', $userId);
        });
    }

    private function anonymize(Request $request): Response
    {
        if ($request->postString('confirm') !== '1') {
            return $this->confirmedMutationError($request, 'Bitte bestätigen Sie die endgültige Anonymisierung.');
        }

        return $this->lifecycleMutation($request, 'anonymized', function (AuthenticatedStaff $staff, int $userId): void {
            $this->users->anonymize($userId, $staff->id);
            $this->audit->staff($staff, 'staff_user.anonymized', 'staff_user', $userId);
        });
    }

    private function delete(Request $request): Response
    {
        if ($request->postString('confirm') !== '1') {
            return $this->confirmedMutationError($request, 'Bitte bestätigen Sie das endgültige Löschen.');
        }

        return $this->lifecycleMutation($request, 'deleted', function (AuthenticatedStaff $staff, int $userId): void {
            $this->users->delete($userId, $staff->id);
            $this->audit->staff($staff, 'staff_user.deleted', 'staff_user', $userId);
        });
    }

    /** @param callable(AuthenticatedStaff, int): void $operation */
    private function lifecycleMutation(Request $request, string $success, callable $operation): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], [], '', 419);
        }

        try {
            $userId = $this->positiveInt($request->postString('user_id'));
            $operation($staff, $userId);
            $this->csrf->rotate();

            return Response::redirect('/admin/users?saved=' . rawurlencode($success));
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], [], '', 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception);
        }
    }

    private function confirmedMutationError(Request $request, string $message): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], [], '', 419);
        }

        return $this->page($staff, [$message], [], '', 422);
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
     * @param array<string, string> $form
     */
    private function page(
        AuthenticatedStaff $staff,
        array $errors = [],
        array $form = [],
        string $success = '',
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('admin-users.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'form' => $form,
            'success' => $success,
            'users' => $this->users->all(),
            'roles' => StaffRole::cases(),
        ]), $status);
    }

    /** @param array<string, string> $form */
    private function failure(AuthenticatedStaff $staff, Throwable $exception, array $form = []): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Local user administration failed', [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'exception' => $exception,
        ]);

        return $this->page(
            $staff,
            ['Die Benutzeränderung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
            $form,
            '',
            500,
        );
    }

    private function positiveInt(string $value): int
    {
        if (!preg_match('/^\d+$/', $value) || (int) $value < 1) {
            throw new DomainException('Das Benutzerkonto ist ungültig.');
        }

        return (int) $value;
    }

    /** @return array<string, string> */
    private function safeForm(Request $request): array
    {
        return [
            'username' => $request->postString('username'),
            'display_name' => $request->postString('display_name'),
            'email' => $request->postString('email'),
            'role' => $request->postString('role'),
        ];
    }
}
