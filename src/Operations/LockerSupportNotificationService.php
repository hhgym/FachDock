<?php

declare(strict_types=1);

namespace FachDock\Operations;

use FachDock\Mail\MailQueueService;
use Psr\Log\LoggerInterface;
use Throwable;

final class LockerSupportNotificationService
{
    public function __construct(
        private readonly MailQueueService $queue,
        private readonly string $baseUrl,
        private readonly string $schoolName,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $incident */
    public function received(array $incident): void
    {
        $this->enqueue('locker_issue_received', $incident, [
            'school_name' => $this->schoolName !== '' ? $this->schoolName : 'der Schule',
            'description' => (string) ($incident['description'] ?? ''),
        ], 'received');
    }

    /** @param array<string, mixed> $incident */
    public function resolved(array $incident): void
    {
        $this->enqueue('locker_issue_resolved', $incident, [
            'resolution_note' => trim((string) ($incident['resolution_note'] ?? '')) !== ''
                ? (string) $incident['resolution_note']
                : 'Der Vorgang wurde durch die Schließfachverwaltung abgeschlossen.',
        ], 'resolved');
    }

    /**
     * @param array<string, mixed> $incident
     * @param array<string, scalar|null> $extra
     */
    private function enqueue(string $templateKey, array $incident, array $extra, string $event): void
    {
        $email = trim((string) ($incident['reporter_email'] ?? ''));
        $id = (int) ($incident['id'] ?? 0);
        if ($id < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $reportedBy = (string) ($incident['reported_by_type'] ?? '');
        $path = $reportedBy === 'student' ? '/student/support' : '/parent/support';
        $category = LockerIncidentCategory::tryFrom((string) ($incident['category'] ?? ''));
        $name = trim((string) ($incident['reporter_name'] ?? ''));
        $placeholders = [
            'incident_reference' => '#' . $id,
            'recipient_name_suffix' => $name === '' ? '' : ' ' . $name,
            'locker_name' => (string) ($incident['locker_name'] ?? ''),
            'student_name' => (string) ($incident['student_name'] ?? ''),
            'category_label' => $category?->label() ?? 'Schließfachproblem',
            'status_url' => rtrim($this->baseUrl, '/') . $path,
        ] + $extra;

        try {
            $this->queue->enqueue(
                $templateKey,
                $email,
                $name !== '' ? $name : null,
                $placeholders,
                [],
                'locker_incident',
                $id,
                'locker-incident-' . $id,
                'locker-incident:' . $id . ':' . $event,
                $event === 'received' ? 40 : 50,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Locker support notification could not be queued', [
                'incident_id' => $id,
                'event' => $event,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
