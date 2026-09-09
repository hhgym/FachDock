<?php

declare(strict_types=1);

namespace FachDock\Operations;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class ParentSupportController
{
    public function __construct(
        private readonly LockerSupportService $support,
        private readonly ParentSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $parent = $this->sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        return $this->page(
            $parent,
            [],
            ($request->query()['reported'] ?? null) === '1',
        );
    }

    public function report(Request $request): Response
    {
        $parent = $this->sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($parent, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $bookingId = $this->positiveInt($request->postString('booking_id'));
            $id = $this->support->reportFromParent(
                $parent,
                $bookingId,
                $request->postString('category'),
                $request->postString('description'),
            );
            $this->audit->parent($parent, 'locker.incident.reported', 'locker_incident', $id, [
                'booking_id' => $bookingId,
                'ip_address' => $request->clientIp(),
            ]);
            $this->csrf->rotate();

            return Response::redirect('/parent/support?reported=1');
        } catch (DomainException $exception) {
            return $this->page($parent, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Parent locker support report failed', [
                'error_id' => $errorId,
                'parent_contact_id' => $parent->id,
                'exception' => $exception,
            ]);

            return $this->page(
                $parent,
                ['Die Meldung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                false,
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private function page(
        \FachDock\Parent\AuthenticatedParent $parent,
        array $errors,
        bool $success,
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('parent-support.php', [
            'parent' => $parent,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'assignments' => $this->support->reportableForParent($parent->id),
            'incidents' => $this->support->incidentsForParent($parent->id),
            'categories' => LockerIncidentCategory::cases(),
        ]), $status);
    }

    private function positiveInt(string $value): int
    {
        $value = trim($value);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        return (int) $value;
    }
}
