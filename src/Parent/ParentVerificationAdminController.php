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
use Psr\Log\LoggerInterface;
use Throwable;

final class ParentVerificationAdminController
{
    public function __construct(
        private readonly ParentPortalAccessService $access,
        private readonly StaffSessionService $staffSessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->post('/admin/parents/send-verification', fn (Request $request): Response => $this->send($request));
    }

    private function send(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $parentId = $this->positiveInt($request->postString('parent_contact_id'));
            $queueId = $this->access->sendVerification(
                $parentId,
                $request->clientIp(),
                $request->userAgent(),
            );
            $this->audit->staff($staff, 'parent.verification_mail_queued', 'parent_contact', $parentId, [
                'mail_queue_id' => $queueId,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/parents');
        } catch (DomainException $exception) {
            return Response::html(
                '<h1>Bestätigungslink konnte nicht erzeugt werden</h1><p>'
                . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</p><p><a href="/admin/parents">Zurück</a></p>',
                422,
            );
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Parent verification mail could not be queued', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return Response::html(
                '<h1>Fehler</h1><p>Der Bestätigungslink konnte nicht versendet werden. Fehler-ID: <code>'
                . $errorId . '</code></p>',
                500,
            );
        }
    }

    private function administrator(): AuthenticatedStaff|Response
    {
        $staff = $this->staffSessions->current();
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
        if (!preg_match('/^\d+$/', trim($value))) {
            throw new DomainException('Der Elternkontakt ist ungültig.');
        }
        $id = (int) trim($value);
        if ($id < 1) {
            throw new DomainException('Der Elternkontakt ist ungültig.');
        }

        return $id;
    }
}
