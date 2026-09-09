<?php

declare(strict_types=1);

namespace FachDock\SchoolYear;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class SchoolYearTransitionController
{
    public function __construct(
        private readonly SchoolYearTransitionService $transitions,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page($request, $staff);
    }

    public function remind(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $sourceId = $this->positiveInt($request->postString('source_school_year_id'), 'Quellschuljahr');
            $targetId = $this->positiveInt($request->postString('target_school_year_id'), 'Zielschuljahr');
            $stage = trim($request->postString('reminder_key'));
            $queued = $this->transitions->queueReminderStage($sourceId, $targetId, $stage);
            $this->audit->staff($staff, 'school_year.reminders_queued', 'school_year', $sourceId, [
                'target_school_year_id' => $targetId,
                'reminder_key' => $stage,
                'queued' => $queued,
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($sourceId, $targetId, 'reminders=' . $queued));
        } catch (DomainException $exception) {
            return $this->page($request, $staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    /** @throws JsonException */
    public function apply(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }
        if (trim($request->postString('confirmation')) !== 'SCHULJAHRESWECHSEL') {
            return $this->page(
                $request,
                $staff,
                ['Zur Bestätigung muss exakt „SCHULJAHRESWECHSEL“ eingegeben werden.'],
                422,
            );
        }

        try {
            $sourceId = $this->positiveInt($request->postString('source_school_year_id'), 'Quellschuljahr');
            $targetId = $this->positiveInt($request->postString('target_school_year_id'), 'Zielschuljahr');
            $summary = $this->transitions->applyRollover($sourceId, $targetId, 'staff', $staff->id);
            $this->audit->staff($staff, 'school_year.rollover_applied', 'school_year', $sourceId, [
                'target_school_year_id' => $targetId,
                'summary' => $summary,
            ]);
            $this->csrf->rotate();

            return Response::redirect($this->selectionUrl($sourceId, $targetId, 'rollover=done'));
        } catch (DomainException $exception) {
            return $this->page($request, $staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    /** @param list<string> $errors */
    private function page(Request $request, AuthenticatedStaff $staff, array $errors = [], int $status = 200): Response
    {
        $pairs = $this->transitions->transitionPairs();
        $sourceId = $this->optionalPositiveInt($this->requestValue($request, 'source_school_year_id'));
        $targetId = $this->optionalPositiveInt($this->requestValue($request, 'target_school_year_id'));
        if (($sourceId === null || $targetId === null) && $pairs !== []) {
            $sourceId = (int) $pairs[0]['source_id'];
            $targetId = (int) $pairs[0]['target_id'];
        }

        $preview = null;
        if ($sourceId !== null && $targetId !== null) {
            try {
                $preview = $this->transitions->preview($sourceId, $targetId);
            } catch (DomainException $exception) {
                $errors[] = $exception->getMessage();
                $status = max($status, 422);
            }
        }

        $query = $request->query();
        $notice = null;
        if (isset($query['reminders']) && is_scalar($query['reminders'])) {
            $notice = (int) $query['reminders'] . ' Erinnerung(en) wurden neu in die E-Mail-Warteschlange gestellt.';
        } elseif (($query['rollover'] ?? null) === 'done') {
            $notice = 'Der Schuljahreswechsel wurde erfolgreich durchgeführt beziehungsweise war bereits abgeschlossen.';
        }

        return Response::html($this->views->render('school-year-transition.php', [
            'staff' => $staff,
            'pairs' => $pairs,
            'selectedSourceId' => $sourceId,
            'selectedTargetId' => $targetId,
            'preview' => $preview,
            'runs' => $this->transitions->recentRuns(),
            'notice' => $notice,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    private function technicalFailure(Request $request, AuthenticatedStaff $staff, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('School year transition action failed', [
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        return $this->page(
            $request,
            $staff,
            ['Die Aktion konnte nicht abgeschlossen werden. Fehler-ID: ' . $errorId],
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
            return Response::html('<h1>403</h1><p>Administratorrechte erforderlich.</p>', 403);
        }

        return $staff;
    }

    private function selectionUrl(int $sourceId, int $targetId, string $suffix = ''): string
    {
        $url = '/admin/school-year-transition?source_school_year_id=' . $sourceId
            . '&target_school_year_id=' . $targetId;

        return $suffix !== '' ? $url . '&' . $suffix : $url;
    }

    private function requestValue(Request $request, string $key): string
    {
        $post = $request->postString($key);
        if ($post !== '') {
            return $post;
        }
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveInt(string $value, string $label): int
    {
        $parsed = $this->optionalPositiveInt($value);
        if ($parsed === null) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return $parsed;
    }

    private function optionalPositiveInt(string $value): ?int
    {
        return preg_match('/^\d+$/', trim($value)) === 1 && (int) $value > 0 ? (int) $value : null;
    }
}
