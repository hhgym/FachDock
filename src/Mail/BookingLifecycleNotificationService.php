<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class BookingLifecycleNotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailQueueService $queue,
        private readonly string $baseUrl,
        private readonly string $schoolName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function lockerChanged(int $bookingId): void
    {
        $this->safely('booking_locker_changed', function () use ($bookingId): void {
            $context = $this->eventContext($bookingId, 'locker_changed');
            $this->enqueue(
                'booking_locker_changed',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'old_locker_name' => $context['old_locker_name'],
                    'new_locker_name' => $context['new_locker_name'],
                    'school_year' => $context['school_year_label'],
                    'reason' => $context['reason'],
                    'booking_url' => $this->bookingUrl($bookingId),
                ],
                $bookingId,
                'booking-locker-changed:' . $bookingId . ':' . $context['event_id'],
            );
        });
    }

    public function ended(int $bookingId, bool $cancelled): void
    {
        $eventType = $cancelled ? 'cancelled' : 'ended';
        $template = $cancelled ? 'booking_cancelled' : 'booking_ended';
        $this->safely($template, function () use ($bookingId, $eventType, $template, $cancelled): void {
            $context = $this->eventContext($bookingId, $eventType);
            $placeholders = [
                'school_name' => $this->resolvedSchoolName(),
                'parent_name_suffix' => $this->parentNameSuffix($context),
                'student_name' => $context['student_name'],
                'school_year' => $context['school_year_label'],
                'locker_name' => $context['old_locker_name'],
                'reason' => $context['reason'],
                'booking_url' => $this->bookingUrl($bookingId),
            ];
            if (!$cancelled) {
                $effective = DateTimeImmutable::createFromFormat('!Y-m-d', $context['effective_on']);
                $placeholders['effective_on'] = $effective === false
                    ? $context['effective_on']
                    : $effective->format('d.m.Y');
            }
            $this->enqueue(
                $template,
                $context,
                $placeholders,
                $bookingId,
                $template . ':' . $bookingId . ':' . $context['event_id'],
            );
        });
    }

    public function renewed(int $bookingId): void
    {
        $this->safely('booking_renewed', function () use ($bookingId): void {
            $context = $this->bookingContext($bookingId);
            $nextStep = match ($context['status']) {
                'payment_due' => 'Für die Verlängerung ist noch eine Zahlung erforderlich.',
                'exemption_review' => 'Die Verlängerung wurde zur erneuten BuT-Prüfung vorgemerkt.',
                default => 'Die Verlängerung ist bereits aktiv.',
            };
            $this->enqueue(
                'booking_renewed',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'school_year' => $context['school_year_label'],
                    'locker_name' => $context['locker_short_name'],
                    'next_step' => $nextStep,
                    'booking_url' => $this->bookingUrl($bookingId),
                ],
                $bookingId,
                'booking-renewed:' . $bookingId,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function eventContext(int $bookingId, string $eventType): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id AS event_id, e.reason, e.effective_on, old_l.short_name AS old_locker_name, '
            . 'new_l.short_name AS new_locker_name, pc.email AS parent_email, pc.first_name AS parent_first_name, '
            . 'pc.last_name AS parent_last_name, s.first_name AS student_first_name, s.last_name AS student_last_name, '
            . 'sy.label AS school_year_label '
            . 'FROM booking_lifecycle_events e INNER JOIN bookings b ON b.id = e.booking_id '
            . 'INNER JOIN students s ON s.id = b.student_id INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN lockers old_l ON old_l.id = e.old_locker_id LEFT JOIN lockers new_l ON new_l.id = e.new_locker_id '
            . "LEFT JOIN parent_contacts pc ON b.initiated_by_type = 'parent' AND pc.id = b.initiated_by_id "
            . 'WHERE e.booking_id = :booking_id AND e.event_type = :event_type '
            . 'ORDER BY e.created_at DESC, e.id DESC LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'event_type' => $eventType]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Das Buchungsereignis für die Benachrichtigung wurde nicht gefunden.');
        }

        return $this->normalizedContext($row) + [
            'event_id' => (int) $row['event_id'],
            'reason' => (string) $row['reason'],
            'effective_on' => $row['effective_on'] === null ? '' : (string) $row['effective_on'],
            'old_locker_name' => (string) ($row['old_locker_name'] ?? ''),
            'new_locker_name' => (string) ($row['new_locker_name'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function bookingContext(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.status, pc.email AS parent_email, pc.first_name AS parent_first_name, '
            . 'pc.last_name AS parent_last_name, s.first_name AS student_first_name, s.last_name AS student_last_name, '
            . 'sy.label AS school_year_label, l.short_name AS locker_short_name '
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id LEFT JOIN lockers l ON l.id = lo.locker_id '
            . "LEFT JOIN parent_contacts pc ON b.initiated_by_type = 'parent' AND pc.id = b.initiated_by_id "
            . 'WHERE b.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Die Buchung für die Benachrichtigung wurde nicht gefunden.');
        }

        return $this->normalizedContext($row) + [
            'status' => (string) $row['status'],
            'locker_short_name' => (string) ($row['locker_short_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizedContext(array $row): array
    {
        $email = $row['parent_email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Für die Buchung ist kein benachrichtigbarer Elternkontakt hinterlegt.');
        }

        return [
            'parent_email' => $email,
            'parent_first_name' => $row['parent_first_name'] === null ? null : (string) $row['parent_first_name'],
            'parent_last_name' => $row['parent_last_name'] === null ? null : (string) $row['parent_last_name'],
            'student_name' => trim((string) $row['student_first_name'] . ' ' . (string) $row['student_last_name']),
            'school_year_label' => (string) $row['school_year_label'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, scalar|null> $placeholders
     */
    private function enqueue(
        string $templateKey,
        array $context,
        array $placeholders,
        int $bookingId,
        string $deduplicationKey,
    ): void {
        $name = trim((string) ($context['parent_first_name'] ?? '') . ' ' . (string) ($context['parent_last_name'] ?? ''));
        $this->queue->enqueue(
            $templateKey,
            (string) $context['parent_email'],
            $name === '' ? null : $name,
            $placeholders,
            [],
            'booking',
            $bookingId,
            $deduplicationKey,
            $deduplicationKey,
            40,
        );
    }

    /** @param array<string, mixed> $context */
    private function parentNameSuffix(array $context): string
    {
        $name = trim((string) ($context['parent_first_name'] ?? '') . ' ' . (string) ($context['parent_last_name'] ?? ''));

        return $name === '' ? '' : ' ' . $name;
    }

    private function bookingUrl(int $bookingId): string
    {
        return rtrim($this->baseUrl, '/') . '/parent/booking/status?booking_id=' . $bookingId;
    }

    private function resolvedSchoolName(): string
    {
        $name = trim($this->schoolName);

        return $name === '' ? 'FachDock' : $name;
    }

    private function safely(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->logger->error('Booking lifecycle notification failed', [
                'operation' => $operation,
                'exception' => $exception,
            ]);
        }
    }
}
