<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class BookingDueStripePaymentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingPaymentGateway $stripe,
        private readonly string $baseUrl,
        private readonly string $currency = 'EUR',
        private readonly int $checkoutMinutes = 30,
    ) {
    }

    public function start(AuthenticatedParent $parent, int $bookingId): PaymentStartResult
    {
        $context = $this->bookingForParent($parent, $bookingId);
        if ($context['amount_cents'] < 1) {
            throw new DomainException('Für diese Buchung ist kein offener Zahlungsbetrag vorhanden.');
        }

        $this->assertHttpsBaseUrl();
        $paymentId = null;

        try {
            $paymentId = $this->createPaymentAttempt($parent->id, $bookingId, $context);
            $customerId = $this->stripe->ensureCustomer(
                $parent->id,
                $parent->email,
                $this->parentName($parent),
                $context['stripe_customer_id'],
            );
            $this->persistCustomerId($parent->id, $customerId);

            $baseUrl = rtrim(trim($this->baseUrl), '/');
            $successUrl = $baseUrl . '/parent/payment/return?payment_id=' . $paymentId
                . '&session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $baseUrl . '/parent/booking/status?booking_id=' . $bookingId . '&payment_cancelled=1';
            $minutes = max(30, min(1440, $this->checkoutMinutes));
            $session = $this->stripe->createBookingCheckoutSession(
                $paymentId,
                $bookingId,
                $customerId,
                $context['amount_cents'],
                $this->currency,
                'Schließfach ' . $context['school_year_label'] . ' · ' . $context['locker_short_name'],
                $successUrl,
                $cancelUrl,
                time() + ($minutes * 60),
            );
            $this->activateCheckout($paymentId, $session);

            return PaymentStartResult::checkout($paymentId, $session->url);
        } catch (Throwable $exception) {
            if ($paymentId !== null) {
                $this->failStartingPayment($paymentId, $bookingId, $exception);
            }

            throw $exception;
        }
    }

    public function handleWebhookIfBookingPayment(string $payload, string $signature): bool
    {
        $event = $this->stripe->verifyWebhook($payload, $signature);
        $payment = $this->paymentBySession($event->checkoutSessionId);
        if ($payment === null || $payment['reservation_id'] !== null || $payment['booking_id'] === null) {
            return false;
        }

        if (!$this->registerWebhookEvent($event)) {
            return true;
        }

        try {
            match ($event->type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' =>
                    $this->handleSuccessfulEvent($event),
                'checkout.session.async_payment_failed' =>
                    $this->handleTerminalEvent($event, PaymentStatus::Failed, 'stripe_async_payment_failed'),
                'checkout.session.expired' =>
                    $this->handleTerminalEvent($event, PaymentStatus::Expired, 'stripe_checkout_expired'),
                default => $this->markEventProcessed($event->id, $payment['payment_id'], 'ignored'),
            };
        } catch (Throwable $exception) {
            $this->markEventError($event->id, $exception->getMessage());
            throw $exception;
        }

        return true;
    }

    private function handleSuccessfulEvent(StripeWebhookEvent $event): void
    {
        if ($event->type === 'checkout.session.completed' && $event->paymentStatus !== 'paid') {
            $payment = $this->paymentBySession($event->checkoutSessionId);
            $this->markEventProcessed($event->id, $payment['payment_id'] ?? null, 'awaiting_payment');

            return;
        }

        $this->pdo->beginTransaction();
        try {
            $payment = $this->paymentBySessionForUpdate($event->checkoutSessionId);
            if ($payment === null || $payment['booking_id'] === null) {
                $this->updateEventWithinTransaction($event->id, null, 'processed', 'Payment nicht gefunden.');
                $this->pdo->commit();

                return;
            }
            if ($payment['status'] === PaymentStatus::Paid->value) {
                $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
                $this->pdo->commit();

                return;
            }
            if ($payment['status'] === PaymentStatus::ManualReview->value) {
                $this->updateEventWithinTransaction(
                    $event->id,
                    $payment['payment_id'],
                    'processed',
                    'Zahlung befindet sich bereits in manueller Prüfung.',
                );
                $this->pdo->commit();

                return;
            }

            $booking = $this->pdo->prepare('SELECT status FROM bookings WHERE id = :id FOR UPDATE');
            $booking->execute(['id' => $payment['booking_id']]);
            $bookingStatus = $booking->fetchColumn();
            if (!is_string($bookingStatus) || !in_array($bookingStatus, ['payment_due', 'active'], true)) {
                throw new RuntimeException('Die zugehörige Buchung kann nicht als bezahlt aktiviert werden.');
            }

            if ($bookingStatus === 'payment_due') {
                $this->pdo->prepare(
                    "UPDATE bookings SET status = 'active', payment_due_at = NULL, updated_at = CURRENT_TIMESTAMP "
                    . "WHERE id = :id AND status = 'payment_due'"
                )->execute(['id' => $payment['booking_id']]);
            }

            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'paid', checkout_url = NULL, "
                . 'stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), '
                . 'processing_started_at = NULL, failure_code = NULL, failure_message = NULL, '
                . 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $payment['payment_id'],
                'intent_id' => $event->paymentIntentId,
            ]);
            $this->pdo->prepare('DELETE FROM booking_payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $payment['payment_id']]);
            $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->markPaidPaymentForManualReview($event, $exception);
        }
    }

    private function handleTerminalEvent(
        StripeWebhookEvent $event,
        PaymentStatus $terminalStatus,
        string $failureCode,
    ): void {
        $this->pdo->beginTransaction();
        try {
            $payment = $this->paymentBySessionForUpdate($event->checkoutSessionId);
            if ($payment === null) {
                $this->updateEventWithinTransaction($event->id, null, 'processed', 'Payment nicht gefunden.');
                $this->pdo->commit();

                return;
            }
            if (in_array($payment['status'], [
                PaymentStatus::Paid->value,
                PaymentStatus::ManualReview->value,
                PaymentStatus::ProcessingPaid->value,
            ], true)) {
                $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
                $this->pdo->commit();

                return;
            }

            $statement = $this->pdo->prepare(
                'UPDATE payments SET status = :status, checkout_url = NULL, failure_code = :failure_code, '
                . 'failure_message = :failure_message, processing_started_at = NULL, failed_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $payment['payment_id'],
                'status' => $terminalStatus->value,
                'failure_code' => $failureCode,
                'failure_message' => $terminalStatus === PaymentStatus::Expired
                    ? 'Die Stripe Checkout Session ist abgelaufen.'
                    : 'Stripe hat die Zahlung als fehlgeschlagen gemeldet.',
            ]);
            $this->pdo->prepare('DELETE FROM booking_payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $payment['payment_id']]);
            $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function markPaidPaymentForManualReview(StripeWebhookEvent $event, Throwable $exception): void
    {
        $payment = $this->paymentBySession($event->checkoutSessionId);
        if ($payment === null) {
            throw $exception;
        }

        $message = mb_substr($exception->getMessage(), 0, 1000);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'manual_review', checkout_url = NULL, "
                . 'stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), '
                . "failure_code = 'paid_booking_activation_failed', failure_message = :message, processing_started_at = NULL, "
                . 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $payment['payment_id'],
                'intent_id' => $event->paymentIntentId,
                'message' => $message,
            ]);
            $this->pdo->prepare('DELETE FROM booking_payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $payment['payment_id']]);
            $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', $message);
            $this->pdo->commit();
        } catch (Throwable $innerException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $innerException;
        }
    }

    /**
     * @param array{amount_cents: int, annual_fee_cents: int, proration_months: int} $context
     */
    private function createPaymentAttempt(int $parentContactId, int $bookingId, array $context): int
    {
        $this->pdo->beginTransaction();
        try {
            $booking = $this->pdo->prepare(
                "SELECT id FROM bookings WHERE id = :id AND status = 'payment_due' FOR UPDATE"
            );
            $booking->execute(['id' => $bookingId]);
            if ($booking->fetchColumn() === false) {
                throw new DomainException('Die Buchung ist nicht mehr für eine Zahlung verfügbar.');
            }

            $existing = $this->pdo->prepare(
                'SELECT p.id FROM booking_payment_attempt_slots s '
                . 'INNER JOIN payments p ON p.id = s.payment_id WHERE s.booking_id = :booking_id FOR UPDATE'
            );
            $existing->execute(['booking_id' => $bookingId]);
            if ($existing->fetchColumn() !== false) {
                throw new DomainException('Für diese Buchung läuft bereits ein Zahlungsvorgang.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO payments '
                . '(reservation_id, booking_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, '
                . 'proration_months, created_at, updated_at) '
                . "VALUES (NULL, :booking_id, :parent_contact_id, 'stripe', 'creating', :amount_cents, :currency, "
                . ':annual_fee_cents, :proration_months, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'booking_id' => $bookingId,
                'parent_contact_id' => $parentContactId,
                'amount_cents' => $context['amount_cents'],
                'currency' => mb_strtoupper($this->currency),
                'annual_fee_cents' => $context['annual_fee_cents'],
                'proration_months' => $context['proration_months'],
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();
            if ($paymentId < 1) {
                throw new RuntimeException('Der Zahlungsvorgang konnte nicht angelegt werden.');
            }

            $slot = $this->pdo->prepare(
                'INSERT INTO booking_payment_attempt_slots (booking_id, payment_id, created_at) '
                . 'VALUES (:booking_id, :payment_id, CURRENT_TIMESTAMP)'
            );
            $slot->execute([
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
            ]);
            $this->pdo->commit();

            return $paymentId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function activateCheckout(int $paymentId, StripeCheckoutSession $session): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments SET status = 'checkout_open', stripe_checkout_session_id = :session_id, "
            . 'checkout_url = :checkout_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $paymentId,
            'session_id' => $session->id,
            'checkout_url' => $session->url,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Die Stripe Checkout Session konnte nicht gespeichert werden.');
        }
    }

    private function failStartingPayment(int $paymentId, int $bookingId, Throwable $exception): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'failed', checkout_url = NULL, failure_code = 'checkout_start_failed', "
                . 'failure_message = :message, failed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $paymentId,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            $this->pdo->prepare(
                'DELETE FROM booking_payment_attempt_slots WHERE booking_id = :booking_id AND payment_id = :payment_id'
            )->execute([
                'booking_id' => $bookingId,
                'payment_id' => $paymentId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $innerException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $innerException;
        }
    }

    /**
     * @return array{
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     school_year_label: string,
     *     locker_short_name: string,
     *     stripe_customer_id: string|null
     * }
     */
    private function bookingForParent(AuthenticatedParent $parent, int $bookingId): array
    {
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT b.charged_fee_cents, b.annual_fee_cents, b.proration_months, sy.label AS school_year_label, '
            . 'l.short_name AS locker_short_name, pc.stripe_customer_id '
            . 'FROM bookings b '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'INNER JOIN parent_contacts pc ON pc.id = :parent_contact_id '
            . 'WHERE b.id = :booking_id AND psl.parent_contact_id = :parent_contact_id '
            . "AND b.status = 'payment_due' LIMIT 1"
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'parent_contact_id' => $parent->id,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Diese Buchung ist nicht mehr für eine Zahlung verfügbar.');
        }

        return [
            'amount_cents' => (int) ($row['charged_fee_cents'] ?? 0),
            'annual_fee_cents' => (int) ($row['annual_fee_cents'] ?? 0),
            'proration_months' => (int) ($row['proration_months'] ?? 12),
            'school_year_label' => (string) $row['school_year_label'],
            'locker_short_name' => (string) $row['locker_short_name'],
            'stripe_customer_id' => $row['stripe_customer_id'] === null ? null : (string) $row['stripe_customer_id'],
        ];
    }

    /** @return array{payment_id: int, reservation_id: int|null, booking_id: int|null, status: string}|null */
    private function paymentBySession(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, reservation_id, booking_id, status FROM payments WHERE stripe_checkout_session_id = :session_id LIMIT 1'
        );
        $statement->execute(['session_id' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'payment_id' => (int) $row['id'],
            'reservation_id' => $row['reservation_id'] === null ? null : (int) $row['reservation_id'],
            'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'status' => (string) $row['status'],
        ];
    }

    /** @return array{payment_id: int, booking_id: int|null, status: string}|null */
    private function paymentBySessionForUpdate(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, booking_id, status FROM payments WHERE stripe_checkout_session_id = :session_id FOR UPDATE'
        );
        $statement->execute(['session_id' => $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'payment_id' => (int) $row['id'],
            'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'status' => (string) $row['status'],
        ];
    }

    private function registerWebhookEvent(StripeWebhookEvent $event): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO stripe_webhook_events (stripe_event_id, event_type, status, received_at) '
                . "VALUES (:event_id, :event_type, 'processing', CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return true;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $statement = $this->pdo->prepare(
                'SELECT status FROM stripe_webhook_events WHERE stripe_event_id = :event_id'
            );
            $statement->execute(['event_id' => $event->id]);

            return $statement->fetchColumn() !== 'processed';
        }
    }

    private function markEventProcessed(string $eventId, ?int $paymentId, ?string $message): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE stripe_webhook_events SET status = 'processed', payment_id = :payment_id, "
            . 'processed_at = CURRENT_TIMESTAMP, error_message = :message WHERE stripe_event_id = :event_id'
        );
        $statement->execute([
            'event_id' => $eventId,
            'payment_id' => $paymentId,
            'message' => $message,
        ]);
    }

    private function markEventError(string $eventId, string $message): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE stripe_webhook_events SET status = 'error', error_message = :message "
            . 'WHERE stripe_event_id = :event_id'
        );
        $statement->execute([
            'event_id' => $eventId,
            'message' => mb_substr($message, 0, 1000),
        ]);
    }

    private function updateEventWithinTransaction(
        string $eventId,
        ?int $paymentId,
        string $status,
        ?string $message,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE stripe_webhook_events SET status = :status, payment_id = :payment_id, '
            . 'processed_at = CURRENT_TIMESTAMP, error_message = :message WHERE stripe_event_id = :event_id'
        );
        $statement->execute([
            'event_id' => $eventId,
            'payment_id' => $paymentId,
            'status' => $status,
            'message' => $message === null ? null : mb_substr($message, 0, 1000),
        ]);
    }

    private function persistCustomerId(int $parentContactId, string $customerId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE parent_contacts SET stripe_customer_id = COALESCE(stripe_customer_id, :customer_id), '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $parentContactId,
            'customer_id' => $customerId,
        ]);
    }

    private function parentName(AuthenticatedParent $parent): ?string
    {
        $name = trim(($parent->firstName ?? '') . ' ' . ($parent->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    private function assertHttpsBaseUrl(): void
    {
        if (!preg_match('#^https://[^/]+(?:/.*)?$#i', rtrim(trim($this->baseUrl), '/'))) {
            throw new DomainException('Für Stripe Checkout muss eine kanonische HTTPS-Basis-URL konfiguriert sein.');
        }
    }
}
