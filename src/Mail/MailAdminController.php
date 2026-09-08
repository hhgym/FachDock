<?php

declare(strict_types=1);

namespace FachDock\Mail;

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

final class MailAdminController
{
    public function __construct(
        private readonly MailTemplateService $templates,
        private readonly MailQueueService $queue,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
        private readonly bool $smtpConfigured,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/mail', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/mail/template', fn (Request $request): Response => $this->updateTemplate($request));
        $router->post('/admin/mail/retry', fn (Request $request): Response => $this->retry($request));
        $router->post('/admin/mail/cancel', fn (Request $request): Response => $this->cancel($request));
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

    private function updateTemplate(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $templateKey = $request->postString('template_key');
            $id = $this->templates->createVersion(
                $templateKey,
                $request->postString('subject_template'),
                $request->postString('html_template'),
                $request->postString('text_template'),
                $request->postString('active') === '1',
                $staff->id,
            );
            $this->audit->staff($staff, 'mail_template.version_created', 'mail_template', $id, [
                'template_key' => $templateKey,
            ]);
        });
    }

    private function retry(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $queueId = $this->positiveInt($request->postString('queue_id'));
            $this->queue->retryNow($queueId);
            $this->audit->staff($staff, 'mail_queue.retry_requested', 'mail_queue', $queueId);
        });
    }

    private function cancel(Request $request): Response
    {
        return $this->mutate($request, function (AuthenticatedStaff $staff) use ($request): void {
            $queueId = $this->positiveInt($request->postString('queue_id'));
            $this->queue->cancel($queueId);
            $this->audit->staff($staff, 'mail_queue.canceled', 'mail_queue', $queueId);
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
            return Response::redirect('/admin/mail');
        } catch (DomainException $exception) {
            return $this->page($staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Mail administration failed', [
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

    /** @param list<string> $errors */
    private function page(AuthenticatedStaff $staff, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->views->render('mail.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'templates' => $this->templates->latest(),
            'queue' => $this->queue->recent(),
            'smtpConfigured' => $this->smtpConfigured,
        ]), $status);
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
        if (!preg_match('/^\d+$/', trim($value))) {
            throw new DomainException('Die E-Mail-ID ist ungültig.');
        }
        $id = (int) trim($value);
        if ($id < 1) {
            throw new DomainException('Die E-Mail-ID ist ungültig.');
        }

        return $id;
    }
}
