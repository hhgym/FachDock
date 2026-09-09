<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DateTimeImmutable;
use JsonException;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class BookingNotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailQueueService $mailQueue,
        private readonly string $baseUrl,
        private readonly string $schoolName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function bookingConfirmed(int $bookingId): void
    {
        $this->safely('booking_confirmed', function () use ($bookingId): void {
            $this->cancelPaymentReminder($bookingId);
            $context = $this->bookingContext($bookingId);
            $this->enqueue(
                'booking_confirmed',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'school_year' => $context['school_year_label'],
                    'locker_name' => $context['locker_short_name'],
                    'booking_url' => $this->bookingUrl($bookingId),
                ],
                'booking',
                $bookingId,
                'booking-confirmed:' . $bookingId,
            );
        });
    }

    public function butSubmitted(int $bookingId): void
    {
        $this->safely('but_request_received', function () use ($bookingId): void {
            $context = $this->bookingContext($bookingId);
            $this->enqueue(
                'but_request_received',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'school_year' => $context['school_year_label'],
                    'locker_name' => $context['locker_short_name'],
                    'booking_url' => $this->bookingUrl($bookingId),
                ],
                'booking',
                $bookingId,
                'but-request-received:' . $bookingId,
            );
        });
    }

    public function butApproved(int $bookingId): void
    {
        $this->safely('but_approved', function () use ($bookingId): void {
            $this->cancelPaymentReminder($bookingId);
            $context = $this->bookingContext($bookingId);
            $this->enqueue(
                'but_approved',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'school_year' => $context['school_year_label'],
                    'locker_name' => $context['locker_short_name'],
                    'booking_url' => $this->bookingUrl($bookingId),
                ],
                'booking',
                $bookingId,
                'but-approved:' . $bookingId,
            );
        });
    }

    public function butRejected(int $bookingId): void
    {
        $this->safely('but_rejected_payment_due', function () use ($bookingId): void {
            $context = $this->bookingContext($bookingId);
            if ($context['payment_due_at'] === null) {
                throw new RuntimeException('Für die abgelehnte BuT-Buchung fehlt die Zahlungsfrist.');
            }
            $placeholders = [
                'school_name' => $this->resolvedSchoolName(),
                'parent_name_suffix' => $this->parentNameSuffix($context),
                'student_name' => $context['student_name'],
                'amount' => $this->formatMoney($context['charged_fee_cents'], 'EUR'),
                'payment_due_at' => $this->formatDate($context['payment_due_at']),
                'booking_url' => $this->bookingUrl($bookingId),
            ];
            $this->enqueue(
                'but_rejected_payment_due',
                $context,
                $placeholders,
                'booking',
                $bookingId,
                'but-rejected-payment-due:' . $bookingId,
                30,
                $context['payment_due_at'],
            );

            $reminderAt = (new DateTimeImmutable($context['payment_due_at']))->modify('-3 days');
            if ($reminderAt <= new DateTimeImmutable()) {
                return;
            }
            $this->enqueue(
                'payment_due_reminder',
                $context,
                $placeholders,
                'booking',
                $bookingId,
                'payment-due-reminder:' . $bookingId,
                60,
                $context['payment_due_at'],
                $reminderAt->format('Y-m-d H:i:s'),
            );
        });
    }

    public function stripeEventProcessed(string $payload): void
    {
        $this->safely('stripe_payment_notification', function () use ($payload): void {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_string($decoded['id'] ?? null) || trim($decoded['id']) === '') {
                return;
            }

            $statement = $this->pdo->prepare(
                'SELECT swe.status AS event_status, p.id AS payment_id, p.status AS payment_status, p.booking_id '
                . 'FROM stripe_webhook_events swe '
                . 'LEFT JOIN payments p ON p.id = swe.payment_id '
                . 'WHERE swe.stripe_event_id = :event_id LIMIT 1'
            );
            $statement->execute(['event_id' => trim($decoded['id'])]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['event_status'] !== 'processed' || $row['payment_id'] === null) {
                return;
            }

            $paymentId = (int) $row['payment_id'];
            $status = (string) $row['payment_status'];
            if ($status === 'paid') {
                $this->paymentReceived($paymentId);
                if ($row['booking_id'] !== null) {
                    $this->bookingConfirmed((int) $row['booking_id']);
                }

                return;
            }
            if ($status === 'manual_review') {
                $this->paymentManualReview($paymentId);

                return;
            }
            if ($status === 'failed') {
                $this->paymentFailed($paymentId, false);

                return;
            }
            if ($status === 'expired') {
                $this->paymentFailed($paymentId, true);
            }
        });
    }

    public function paymentReceived(int $paymentId): void
    {
        $this->safely('payment_received', function () use ($paymentId): void {
            $context = $this->paymentContext($paymentId);
            if ($context['booking_id'] !== null) {
                $this->cancelPaymentReminder($context['booking_id']);
            }
            $bookingUrl = $context['booking_id'] === null
                ? $this->parentHomeUrl()
                : $this->bookingUrl($context['booking_id']);
            $this->enqueue(
                'payment_received',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'amount' => $this->formatMoney($context['amount_cents'], $context['currency']),
                    'payment_reference' => '#' . $paymentId,
                    'school_year' => $context['school_year_label'],
                    'locker_name' => $context['locker_short_name'],
                    'booking_url' => $bookingUrl,
                ],
                'payment',
                $paymentId,
                'payment-received:' . $paymentId,
                20,
            );
        });
    }

    public function paymentManualReview(int $paymentId): void
    {
        $this->safely('payment_received_booking_pending', function () use ($paymentId): void {
            $context = $this->paymentContext($paymentId);
            $this->enqueue(
                'payment_received_booking_pending',
                $context,
                [
                    'school_name' => $this->resolvedSchoolName(),
                    'parent_name_suffix' => $this->parentNameSuffix($context),
                    'student_name' => $context['student_name'],
                    'amount' => $this->formatMoney($context['amount_cents'], $context['currency']),
                    'payment_reference' => '#' . $paymentId,
                    'payment_url' => $this->paymentUrl($paymentId),
                ],
                'payment',
                $paymentId,
                'payment-received-booking-pending:' . $paymentId,
                10,
            );
        });
    }

    public function paymentFailed(int $paymentId, bool $expired): void
    {
        $this->safely(
            $expired ? 'payment_checkout_expired' : 'payment_failed',
            function () use ($paymentId, $expired): void {
                $context = $this->paymentContext($paymentId);
                $template = $expired ? 'payment_checkout_expired' : 'payment_failed';
                $this->enqueue(
                    $template,
                    $context,
                    [
                        'school_name' => $this->resolvedSchoolName(),
                        'parent_name_suffix' => $this->parentNameSuffix($context),
                        'student_name' => $context['student_name'],
                        'amount' => $this->formatMoney($context['amount_cents'], $context['currency']),
                        'retry_url' => $this->retryUrl($context),
                    ],
                    'payment',
                    $paymentId,
                    $template . ':' . $paymentId,
                    40,
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, scalar|null> $placeholders
     * @throws JsonException
     */
    private function enqueue(
        string $templateKey,
        array $context,
        array $placeholders,
        string $relationType,
        int $relationId,
        string $deduplicationKey,
        int $priority = 100,
        ?string $notAfter = null,
        ?string $availableAt = null,
    ): void {
        $recipientName = trim(
            (string) ($context['parent_first_name'] ?? '') . ' ' . (string) ($context['parent_last_name'] ?? '')
        );
        $this->mailQueue->enqueue(
            $templateKey,
            (string) $context['parent_email'],
            $recipientName === '' ? null : $recipientName,
            $placeholders,
            [],
            $relationType,
            $relationId,
            $deduplicationKey,
            $deduplicationKey,
            $priority,
            $notAfter,
            $availableAt,
        );
    }

    /**
     * @return array{
     *     parent_email: string,
     *     parent_first_name: string|null,
     *     parent_last_name: string|null,
     *     student_name: string,
     *     school_year_label: string,
     *     locker_short_name: string,
     *     charged_fee_cents: int,
     *     payment_due_at: string|null
     * }
     */
    private function bookingContext(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pc.email AS parent_email, pc.first_name AS parent_first_name, pc.last_name AS parent_last_name, '
            . 's.first_name AS student_first_name, s.last_name AS student_last_name, sy.label AS school_year_label, '
            . 'l.short_name AS locker_short_name, b.charged_fee_cents, b.payment_due_at '
            . 'FROM bookings b '
            . 'INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id '
            . "LEFT JOIN parent_contacts pc ON b.initiated_by_type = 'parent' AND pc.id = b.initiated_by_id "
            . 'WHERE b.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['parent_email'] ?? null)
            || !filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Für die Buchungsbenachrichtigung ist kein gültiger Elternkontakt vorhanden.');
        }

        return [
            'parent_email' => $row['parent_email'],
            'parent_first_name' => $row['parent_first_name'] === null ? null : (string) $row['parent_first_name'],
            'parent_last_name' => $row['parent_last_name'] === null ? null : (string) $row['parent_last_name'],
            'student_name' => trim((string) $row['student_first_name'] . ' ' . (string) $row['student_last_name']),
            'school_year_label' => (string) $row['school_year_label'],
            'locker_short_name' => (string) ($row['locker_short_name'] ?? ''),
            'charged_fee_cents' => (int) ($row['charged_fee_cents'] ?? 0),
            'payment_due_at' => $row['payment_due_at'] === null ? null : (string) $row['payment_due_at'],
        ];
    }

    /**
     * @return array{
     *     parent_email: string,
     *     parent_first_name: string|null,
     *     parent_last_name: string|null,
     *     student_name: string,
     *     school_year_label: string,
     *     school_year_id: int,
     *     student_id: int,
     *     locker_short_name: string,
     *     amount_cents: int,
     *     currency: string,
     *     booking_id: int|null
     * }
     */
    private function paymentContext(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pc.email AS parent_email, pc.first_name AS parent_first_name, pc.last_name AS parent_last_name, '
            . 's.id AS student_id, s.first_name AS student_first_name, s.last_name AS student_last_name, '
            . 'sy.id AS school_year_id, sy.label AS school_year_label, l.short_name AS locker_short_name, '
            . 'p.amount_cents, p.currency, p.booking_id '
            . 'FROM payments p '
            . 'LEFT JOIN locker_reservations lr ON lr.id = p.reservation_id '
            . 'LEFT JOIN bookings b ON b.id = p.booking_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN students s ON s.id = COALESCE(b.student_id, lr.student_id) '
            . 'LEFT JOIN school_years sy ON sy.id = COALESCE(b.school_year_id, lr.school_year_id) '
            . 'LEFT JOIN lockers l ON l.id = COALESCE(lo.locker_id, lr.locker_id) '
            . 'INNER JOIN parent_contacts pc ON pc.id = p.parent_contact_id '
            . 'WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $paymentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['parent_email'] ?? null)
            || !filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Für die Zahlungsbenachrichtigung ist kein gültiger Elternkontakt vorhanden.');
        }
        if ($row['student_id'] === null || $row['school_year_id'] === null) {
            throw new RuntimeException('Die Zahlung ist keiner gültigen Buchung oder Reservierung zugeordnet.');
        }

        return [
            'parent_email' => $row['parent_email'],
            'parent_first_name' => $row['parent_first_name'] === null ? null : (string) $row['parent_first_name'],
            'parent_last_name' => $row['parent_last_name'] === null ? null : (string) $row['parent_last_name'],
            'student_name' => trim((string) $row['student_first_name'] . ' ' . (string) $row['student_last_name']),
            'school_year_label' => (string) $row['school_year_label'],
            'school_year_id' => (int) $row['school_year_id'],
            'student_id' => (int) $row['student_id'],
            'locker_short_name' => (string) ($row['locker_short_name'] ?? ''),
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => (string) $row['currency'],
            'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
        ];
    }

    /** @param array<string, mixed> $context */
    private function retryUrl(array $context): string
    {
        if (($context['booking_id'] ?? null) !== null) {
            return $this->bookingUrl((int) $context['booking_id']);
        }

        return $this->absoluteUrl(
            '/parent/booking?student_id=' . (int) $context['student_id']
            . '&school_year_id=' . (int) $context['school_year_id'],
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
        return $this->absoluteUrl('/parent/booking/status?booking_id=' . $bookingId);
    }

    private function paymentUrl(int $paymentId): string
    {
        return $this->absoluteUrl('/parent/payment/return?payment_id=' . $paymentId);
    }

    private function parentHomeUrl(): string
    {
        return $this->absoluteUrl('/parent');
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim(trim($this->baseUrl), '/');

        return $baseUrl === '' ? '' : $baseUrl . $path;
    }

    private function resolvedSchoolName(): string
    {
        $schoolName = trim($this->schoolName);

        return $schoolName === '' ? 'FachDock' : $schoolName;
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $amount = number_format($cents / 100, 2, ',', '.');
        $currency = mb_strtoupper(trim($currency));

        return $currency === 'EUR' ? $amount . ' €' : $amount . ' ' . $currency;
    }

    private function formatDate(string $dateTime): string
    {
        return (new DateTimeImmutable($dateTime))->format('d.m.Y');
    }

    private function cancelPaymentReminder(int $bookingId): void
    {
        $key = 'payment-due-reminder:' . $bookingId;
        $select = $this->pdo->prepare(
            "SELECT id FROM mail_queue WHERE deduplication_key = :key AND status = 'waiting' LIMIT 1"
        );
        $select->execute(['key' => $key]);
        $queueId = $select->fetchColumn();
        if ($queueId === false) {
            return;
        }

        $statement = $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP, html_body = NULL, "
            . 'text_body = NULL, locked_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status = 'waiting'"
        );
        $statement->execute(['id' => (int) $queueId]);
        if ($statement->rowCount() === 1) {
            $this->mailQueue->recordHistory((int) $queueId, 'canceled', null);
        }
    }

    private function safely(string $notification, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->logger->warning('Booking notification could not be queued', [
                'notification' => $notification,
                'exception' => $exception,
            ]);
        }
    }
}
