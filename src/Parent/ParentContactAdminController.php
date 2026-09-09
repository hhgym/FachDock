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
    private const ADMIN_PREVIEW_KEY = 'parent_admin_preview';
    private const PARENT_SESSION_KEY = 'parent_auth_token';
    private const STAFF_SESSION_KEY = 'staff_auth_token';
    private const PREVIEW_LIFETIME_SECONDS = 1800;

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
        $router->post('/admin/parents/preview', fn (Request $request): Response => $this->startPreview($request));
        $router->post('/admin/parents/preview/stop', fn (Request $request): Response => $this->stopPreview($request));
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

    private function startPreview(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $parentContactId = $this->positiveInt($request->postString('parent_contact_id'), 'Elternkontakt');
            $this->assertActiveParentContact($parentContactId);
            $staffToken = $_SESSION[self::STAFF_SESSION_KEY] ?? null;
            if (!is_string($staffToken) || $staffToken === '') {
                throw new DomainException('Die Administrator-Sitzung ist nicht mehr gültig.');
            }

            unset($_SESSION[self::PARENT_SESSION_KEY]);
            $_SESSION[self::ADMIN_PREVIEW_KEY] = [
                'parent_contact_id' => $parentContactId,
                'staff_user_id' => $staff->id,
                'staff_session_id' => $staff->sessionId,
                'staff_token_hash' => hash('sha256', $staffToken),
                'expires_at' => time() + self::PREVIEW_LIFETIME_SECONDS,
            ];
            session_regenerate_id(true);
            $this->audit->staff($staff, 'parent.admin_preview.started', 'parent_contact', $parentContactId);
            $this->csrf->rotate();

            return Response::redirect('/parent');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->failure($staff, $exception);
        }
    }

    private function stopPreview(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $preview = $_SESSION[self::ADMIN_PREVIEW_KEY] ?? null;
        $parentContactId = is_array($preview) && is_int($preview['parent_contact_id'] ?? null)
            ? $preview['parent_contact_id']
            : null;
        unset($_SESSION[self::ADMIN_PREVIEW_KEY], $_SESSION[self::PARENT_SESSION_KEY]);
        session_regenerate_id(true);

        if ($parentContactId !== null) {
            $this->audit->staff($staff, 'parent.admin_preview.ended', 'parent_contact', $parentContactId);
        }
        $this->csrf->rotate();

        return Response::redirect('/admin/parents');
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

    private function assertActiveParentContact(int $parentContactId): void
    {
        foreach ($this->parents->allContacts() as $contact) {
            if ((int) $contact['id'] === $parentContactId && (bool) $contact['active']) {
                return;
            }
        }

        throw new DomainException('Der Elternkontakt ist nicht aktiv oder existiert nicht.');
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
