<?php

declare(strict_types=1);

namespace FachDock\Privacy;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Export\AdminExportService;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Security\Csrf;
use FachDock\System\ProductionReadinessService;
use FachDock\View\ViewRenderer;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class PrivacyController
{
    public function __construct(
        private readonly DataRetentionService $retention,
        private readonly AccountLifecycleService $accountLifecycle,
        private readonly AdminExportService $exports,
        private readonly ProductionReadinessService $readiness,
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

    public function saveSettings(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        try {
            $years = $this->positiveInt($request->postString('retention_years'), 'Aufbewahrungsfrist');
            $days = $this->positiveInt($request->postString('mail_retention_days'), 'E-Mail-Aufbewahrung');
            $studentDeactivation = $this->positiveInt($request->postString('student_deactivation_days'), 'Schüler-Deaktivierungsfrist');
            $studentAnonymization = $this->positiveInt($request->postString('student_anonymization_days'), 'Schüler-Anonymisierungsfrist');
            $parentDeactivation = $this->positiveInt($request->postString('parent_deactivation_days'), 'Eltern-Deaktivierungsfrist');
            $parentAnonymization = $this->positiveInt($request->postString('parent_anonymization_days'), 'Eltern-Anonymisierungsfrist');
            $this->retention->saveSettings($years, $days);
            $this->accountLifecycle->saveSettings(
                $studentDeactivation,
                $studentAnonymization,
                $parentDeactivation,
                $parentAnonymization,
            );
            $this->audit->staff($staff, 'privacy.settings_updated', 'privacy_settings', null, [
                'retention_years' => $years,
                'mail_retention_days' => $days,
                'student_deactivation_days' => $studentDeactivation,
                'student_anonymization_days' => $studentAnonymization,
                'parent_deactivation_days' => $parentDeactivation,
                'parent_anonymization_days' => $parentAnonymization,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/privacy?saved=1');
        } catch (DomainException $exception) {
            return $this->page($request, $staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    /** @throws JsonException */
    public function anonymize(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }
        if (trim($request->postString('confirmation')) !== 'ANONYMISIEREN') {
            return $this->page($request, $staff, ['Zur Bestätigung muss exakt „ANONYMISIEREN“ eingegeben werden.'], 422);
        }

        try {
            $summary = $this->retention->anonymize($staff->id);
            $this->audit->staff($staff, 'privacy.anonymization_run', 'privacy_run', null, $summary);
            $this->csrf->rotate();

            return Response::redirect('/admin/privacy?anonymized=1');
        } catch (DomainException $exception) {
            return $this->page($request, $staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    public function export(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        $type = $this->queryString($request, 'type');
        try {
            $export = $this->exports->export($type);
            $this->audit->staff($staff, 'data.exported', 'export', $type);

            return Response::download($export['content'], $export['filename'], 'text/csv; charset=utf-8');
        } catch (DomainException $exception) {
            return $this->page($request, $staff, [$exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return $this->technicalFailure($request, $staff, $exception);
        }
    }

    /** @param list<string> $errors */
    private function page(Request $request, AuthenticatedStaff $staff, array $errors = [], int $status = 200): Response
    {
        $query = $request->query();
        $notice = null;
        if (($query['saved'] ?? null) === '1') {
            $notice = 'Die Aufbewahrungs- und Account-Lifecycle-Fristen wurden gespeichert.';
        } elseif (($query['anonymized'] ?? null) === '1') {
            $notice = 'Der Datenschutzlauf wurde erfolgreich abgeschlossen.';
        }

        return Response::html($this->views->render('privacy.php', [
            'staff' => $staff,
            'settings' => $this->retention->settings(),
            'accountSettings' => $this->accountLifecycle->settings(),
            'accountPreview' => $this->accountLifecycle->preview(),
            'preview' => $this->retention->preview(),
            'runs' => $this->retention->recentRuns(),
            'readiness' => $this->readiness->snapshot(),
            'notice' => $notice,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    private function technicalFailure(Request $request, AuthenticatedStaff $staff, Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Privacy administration failed', ['error_id' => $errorId, 'exception' => $exception]);

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

    private function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^\d+$/', trim($value)) !== 1 || (int) $value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return (int) $value;
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
