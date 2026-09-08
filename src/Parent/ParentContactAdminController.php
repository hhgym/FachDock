<?php

declare(strict_types=1);

namespace FachDock\Parent;

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

final class ParentContactAdminController
{
    public function __construct(
        private readonly ParentContactService $parents,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/parents', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/parents/link', fn (Request $request): Response => $this->link($request));
        $router->post('/admin/parents/unlink', fn (Request $request): Response => $this->unlink($request));
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

    private function link(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $studentId = $this->positiveInt($request->postString('student_id'), 'Schüler');
            $linkId = $this->parents->linkStudentToEmail(
                $request->postString('email'),
                $studentId,
                $request->postString('first_name'),
                $request->postString('last_name'),
                $staff->id,
            );
            $this->audit->staff($staff, 'parent.student_link.created', 'parent_student_link', $linkId, [
                'student_id' => $studentId,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/parents');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422, $request->post());
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception);
        }
    }

    private function unlink(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $linkId = $this->positiveInt($request->postString('link_id'), 'Verknüpfung');
            $reason = $request->postString('reason');
            $this->parents->deactivateLink($linkId, $staff->id, $reason);
            $this->audit->staff($staff, 'parent.student_link.deactivated', 'parent_student_link', $linkId, [
                'reason' => mb_substr($reason, 0, 255),
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/parents');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception);
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
     * @param array<string, mixed> $form
     */
    private function page(
        AuthenticatedStaff $staff,
        array $errors = [],
        int $status = 200,
        array $form = [],
    ): Response {
        return Response::html($this->views->render('parents.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'form' => $form,
            'contacts' => $this->parents->allContacts(),
            'links' => $this->parents->activeLinks(),
            'students' => $this->parents->activeStudents(),
        ]), $status);
    }

    private function failure(AuthenticatedStaff $staff, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Parent contact administration failed', [
            'error_id' => $errorId,
            'staff_user_id' => $staff->id,
            'exception' => $exception,
        ]);

        return $this->page(
            $staff,
            ['Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
            500,
        );
    }

    private function positiveInt(string $value, string $label): int
    {
        if (!preg_match('/^\d+$/', trim($value)) || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }
}
