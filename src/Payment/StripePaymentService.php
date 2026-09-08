<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DateTimeImmutable;
use DomainException;
use FachDock\Booking\BookingCreationData;
use FachDock\Booking\BookingService;
use FachDock\Booking\BookingStatus;
use FachDock\Booking\FeeCalculator;
use FachDock\Booking\ReservationService;
use FachDock\Booking\SchoolYearPeriod;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class StripePaymentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        private readonly ReservationService $reservations,
        private readonly BookingService $bookings,
        private readonly FeeCalculator $fees,
        private readonly string $baseUrl,
        private readonly string $currency = 'EUR',
        private readonly int $checkoutMinutes = 30,
    ) {
    }

    public function start(AuthenticatedParent $parent, int $reservationId): PaymentStartResult
    {
        $context = $this->reservationForParent($parent, $reservationId);
        $quote = $this->feeQuote($context['annual_fee_cents'], $context['starts_on'], $context['current_date']);

        if ($quote['charged_cents'] === 0) {
            $bookingId = $this->bookings->convertReservation(
                $reservationId,
                new BookingCreationData(
                    BookingStatus::Active,
                    'parent',
                    $parent->id,
                    $context['annual_fee_cents'],
                    0,
                    $quote['months'],
                    'no_fee',
                ),
            );

            return PaymentStartResult::booking($bookingId);
        }

        $this->assertHttpsBaseUrl();
        $this->reservations->startPayment($reservationId, $context['student_id']);
        $paymentId = null;

        try {
            $paymentId = $this->createPaymentAttempt($parent->id, $reservationId, $quote, $context['annual_fee_cents']);
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
            $cancelUrl = $baseUrl . '/parent/booking?student_id=' . $context['student_id']
                . '&school_year_id=' . $context['school_year_id'] . '&payment_cancelled=1';
            $minutes = max(30, min(1440, $this->checkoutMinutes));
            $session = $this->stripe->createCheckoutSession(
                $paymentId,
                $reservationId,
                $customerId,
                $quote['charged_cents'],
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
                $this->failStartingPayment($paymentId, $reservationId, $exception);
            } else {
                $this->restoreReservationAfterPaymentFailure($reservationId);
            }

            throw $exception;
        }
    }

    public function handleWebhook(string $payload, string $signature): void
    {
        $event = $this->stripe->verifyWebhook($payload, $signature);
        if (!$this->registerWebhookEvent($event)) {
            return;
        }

        try {
            match ($event->type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' =>
                    $this->handleSuccessfulEvent($event),
                'checkout.session.async_payment_failed' =>
                    $this->handleTerminalEvent($event, PaymentStatus::Failed, 'stripe_async_payment_failed'),
                'checkout.session.expired' =>
                    $this->handleTerminalEvent($event, PaymentStatus::Expired, 'stripe_checkout_expired'),
                default => $this->markEventProcessed($event->id, null, 'ignored'),
            };
        } catch (Throwable $exception) {
            $this->markEventError($event->id, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @return array{
     *     payment_id: int,
     *     status: string,
     *     amount_cents: int,
     *     currency: string,
     *     booking_id: int|null,
     *     created_at: string,
     *     updated_at: string,
     *     failure_message: string|null
     * }
     */
    public function paymentForParent(AuthenticatedParent $parent, int $paymentId): array
    {
        if ($paymentId < 1) {
            throw new DomainException('Die Zahlung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, status, amount_cents, currency, booking_id, created_at, updated_at, failure_message '
            . 'FROM payments WHERE id = :id AND parent_contact_id = :parent_contact_id LIMIT 1'
        );
        $statement->execute([
            'id' => $paymentId,
            'parent_contact_id' => $parent->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Diese Zahlung ist Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'payment_id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => (string) $row['currency'],
            'booking_id' => $row['booking_id'] !== null ? (int) $row['booking_id'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'failure_message' => $row['failure_message'] !== null ? (string) $row['failure_message'] : null,
        ];
    }

    private function handleSuccessfulEvent(StripeWebhookEvent $event): void
    {
        if ($event->paymentStatus !== 'paid' && $event->type === 'checkout.session.completed') {
            $this->markEventProcessed($event->id, null, 'awaiting_payment');

            return;
        }

        $payment = $this->claimPaymentForPaidProcessing($event);
        if ($payment === null) {
            return;
        }

        try {
            $bookingId = $this->convertedBookingForReservation($payment['reservation_id']);
            if ($bookingId === null) {
                $bookingId = $this->bookings->convertReservation(
                    $payment['reservation_id'],
                    new BookingCreationData(
                        BookingStatus::Active,
                        'parent',
                        $payment['parent_contact_id'],
                        $payment['annual_fee_cents'],
                        $payment['amount_cents'],
                        $payment['proration_months'],
                        null,
                    ),
                );
            }
            $this->finalizePaidPayment($payment['payment_id'], $bookingId, $event);
        } catch (Throwable $exception) {
            $this->markPaidPaymentForManualReview($payment['payment_id'], $event, $exception);
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
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $payment['payment_id']]);
            $this->restoreReservationWithinTransaction($payment['reservation_id']);
            $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *     payment_id: int,
     *     reservation_id: int,
     *     parent_contact_id: int,
     *     status: string,
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     processing_started_at: string|null
     * }|null
     */
    private function claimPaymentForPaidProcessing(StripeWebhookEvent $event): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $payment = $this->paymentBySessionForUpdate($event->checkoutSessionId);
            if ($payment === null) {
                $this->updateEventWithinTransaction($event->id, null, 'processed', 'Payment nicht gefunden.');
                $this->pdo->commit();

                return null;
            }
            if ($payment['status'] === PaymentStatus::Paid->value) {
                $this->updateEventWithinTransaction($event->id, $payment['payment_id'], 'processed', null);
                $this->pdo->commit();

                return null;
            }
            if ($payment['status'] === PaymentStatus::ManualReview->value) {
                $this->updateEventWithinTransaction(
                    $event->id,
                    $payment['payment_id'],
                    'processed',
                    'Zahlung befindet sich bereits in manueller Prüfung.',
                );
                $this->pdo->commit();

                return null;
            }
            if ($payment['status'] === PaymentStatus::ProcessingPaid->value
                && $payment['processing_started_at'] !== null
                && strtotime($payment['processing_started_at']) > time() - 120) {
                throw new RuntimeException('Die bezahlte Stripe-Zahlung wird bereits verarbeitet.');
            }

            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'processing_paid', stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), "
                . 'processing_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $payment['payment_id'],
                'intent_id' => $event->paymentIntentId,
            ]);
            $this->pdo->commit();
            $payment['status'] = PaymentStatus::ProcessingPaid->value;
            $payment['processing_started_at'] = null;

            return $payment;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function finalizePaidPayment(int $paymentId, int $bookingId, StripeWebhookEvent $event): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'paid', booking_id = :booking_id, checkout_url = NULL, "
                . 'stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), '
                . 'processing_started_at = NULL, failure_code = NULL, failure_message = NULL, '
                . 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $paymentId,
                'booking_id' => $bookingId,
                'intent_id' => $event->paymentIntentId,
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->updateEventWithinTransaction($event->id, $paymentId, 'processed', null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function markPaidPaymentForManualReview(
        int $paymentId,
        StripeWebhookEvent $event,
        Throwable $exception,
    ): void {
        $message = mb_substr($exception->getMessage(), 0, 1000);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'manual_review', checkout_url = NULL, "
                . 'stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), '
                . "failure_code = 'paid_booking_failed', failure_message = :message, processing_started_at = NULL, "
                . 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $paymentId,
                'intent_id' => $event->paymentIntentId,
                'message' => $message,
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->updateEventWithinTransaction($event->id, $paymentId, 'processed', $message);
            $this->pdo->commit();
        } catch (Throwable $innerException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $innerException;
        }
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
            $status = $statement->fetchColumn();

            return $status !== 'processed';
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

    /**
     * @param array{months: int, charged_cents: int} $quote
     */
    private function createPaymentAttempt(
        int $parentContactId,
        int $reservationId,
        array $quote,
        int $annualFeeCents,
    ): int {
        $this->pdo->beginTransaction();
        try {
            $reservation = $this->pdo->prepare(
                "SELECT id FROM locker_reservations WHERE id = :id AND status = 'payment_running' "
                . 'AND payment_grace_expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $reservation->execute(['id' => $reservationId]);
            if ($reservation->fetchColumn() === false) {
                throw new DomainException('Die Reservierung ist nicht mehr für eine Zahlung verfügbar.');
            }

            $existing = $this->pdo->prepare(
                'SELECT p.id, p.checkout_url FROM payment_attempt_slots s '
                . 'INNER JOIN payments p ON p.id = s.payment_id WHERE s.reservation_id = :reservation_id FOR UPDATE'
            );
            $existing->execute(['reservation_id' => $reservationId]);
            if ($existing->fetch() !== false) {
                throw new DomainException('Für diese Reservierung läuft bereits ein Zahlungsvorgang.');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO payments '
                . '(reservation_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, '
                . 'proration_months, created_at, updated_at) '
                . "VALUES (:reservation_id, :parent_contact_id, 'stripe', 'creating', :amount_cents, :currency, "
                . ':annual_fee_cents, :proration_months, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'reservation_id' => $reservationId,
                'parent_contact_id' => $parentContactId,
                'amount_cents' => $quote['charged_cents'],
                'currency' => mb_strtoupper($this->currency),
                'annual_fee_cents' => $annualFeeCents,
                'proration_months' => $quote['months'],
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();
            if ($paymentId < 1) {
                throw new RuntimeException('Der Zahlungsvorgang konnte nicht angelegt werden.');
            }
            $slot = $this->pdo->prepare(
                'INSERT INTO payment_attempt_slots (reservation_id, payment_id, created_at) '
                . 'VALUES (:reservation_id, :payment_id, CURRENT_TIMESTAMP)'
            );
            $slot->execute([
                'reservation_id' => $reservationId,
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
            . 'checkout_url = :checkout_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :status'
        );
        $statement->execute([
            'id' => $paymentId,
            'session_id' => $session->id,
            'checkout_url' => $session->url,
            'status' => PaymentStatus::Creating->value,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Die Stripe Checkout Session konnte nicht gespeichert werden.');
        }
    }

    private function failStartingPayment(int $paymentId, int $reservationId, Throwable $exception): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE payments SET status = 'failed', failure_code = 'checkout_creation_failed', "
                . 'failure_message = :message, checkout_url = NULL, failed_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'id' => $paymentId,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->restoreReservationWithinTransaction($reservationId);
            $this->pdo->commit();
        } catch (Throwable $innerException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $innerException;
        }
    }

    private function restoreReservationAfterPaymentFailure(int $reservationId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->restoreReservationWithinTransaction($reservationId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function restoreReservationWithinTransaction(int $reservationId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT status, (expires_at > CURRENT_TIMESTAMP) AS original_active '
            . 'FROM locker_reservations WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $reservationId]);
        $row = $statement->fetch();
        if (!is_array($row) || (string) $row['status'] !== 'payment_running') {
            return;
        }

        if ((int) $row['original_active'] === 1) {
            $this->pdo->prepare(
                "UPDATE locker_reservations SET status = 'active', payment_grace_expires_at = NULL, "
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $reservationId]);

            return;
        }

        $this->pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id')
            ->execute(['id' => $reservationId]);
        $this->pdo->prepare(
            "UPDATE locker_reservations SET status = 'expired', payment_grace_expires_at = NULL, "
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $reservationId]);
    }

    private function persistCustomerId(int $parentContactId, string $customerId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE parent_contacts SET stripe_customer_id = :customer_id, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = :same_customer_id)'
        );
        $statement->execute([
            'id' => $parentContactId,
            'customer_id' => $customerId,
            'same_customer_id' => $customerId,
        ]);
        if ($statement->rowCount() === 0) {
            $lookup = $this->pdo->prepare('SELECT stripe_customer_id FROM parent_contacts WHERE id = :id');
            $lookup->execute(['id' => $parentContactId]);
            $stored = $lookup->fetchColumn();
            if (!is_string($stored) || $stored !== $customerId) {
                throw new RuntimeException('Die Stripe Customer-ID des Elternkontakts ist inkonsistent.');
            }
        }
    }

    /**
     * @return array{
     *     payment_id: int,
     *     reservation_id: int,
     *     parent_contact_id: int,
     *     status: string,
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     processing_started_at: string|null
     * }|null
     */
    private function paymentBySessionForUpdate(string $checkoutSessionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id AS payment_id, reservation_id, parent_contact_id, status, amount_cents, annual_fee_cents, '
            . 'proration_months, processing_started_at FROM payments '
            . 'WHERE stripe_checkout_session_id = :session_id FOR UPDATE'
        );
        $statement->execute(['session_id' => $checkoutSessionId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'payment_id' => (int) $row['payment_id'],
            'reservation_id' => (int) $row['reservation_id'],
            'parent_contact_id' => (int) $row['parent_contact_id'],
            'status' => (string) $row['status'],
            'amount_cents' => (int) $row['amount_cents'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'proration_months' => (int) $row['proration_months'],
            'processing_started_at' => $row['processing_started_at'] !== null
                ? (string) $row['processing_started_at']
                : null,
        ];
    }

    private function convertedBookingForReservation(int $reservationId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id FROM locker_reservations lr '
            . 'INNER JOIN booking_slots bs ON bs.student_id = lr.student_id AND bs.school_year_id = lr.school_year_id '
            . 'INNER JOIN bookings b ON b.id = bs.booking_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id AND lo.locker_id = lr.locker_id '
            . "WHERE lr.id = :reservation_id AND lr.status = 'converted' LIMIT 1"
        );
        $statement->execute(['reservation_id' => $reservationId]);
        $bookingId = $statement->fetchColumn();

        return $bookingId === false ? null : (int) $bookingId;
    }

    /**
     * @return array{
     *     student_id: int,
     *     school_year_id: int,
     *     annual_fee_cents: int,
     *     starts_on: string,
     *     current_date: string,
     *     school_year_label: string,
     *     locker_short_name: string,
     *     stripe_customer_id: string|null
     * }
     */
    private function reservationForParent(AuthenticatedParent $parent, int $reservationId): array
    {
        if ($reservationId < 1) {
            throw new DomainException('Die Reservierung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT lr.student_id, lr.school_year_id, sy.annual_fee_cents, sy.starts_on, '
            . 'CURRENT_DATE AS current_date, sy.label AS school_year_label, l.short_name AS locker_short_name, '
            . 'pc.stripe_customer_id '
            . 'FROM locker_reservations lr '
            . 'INNER JOIN reservation_slots rs ON rs.reservation_id = lr.id '
            . 'INNER JOIN school_years sy ON sy.id = lr.school_year_id '
            . 'INNER JOIN lockers l ON l.id = lr.locker_id '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = lr.student_id '
            . 'INNER JOIN parent_contacts pc ON pc.id = psl.parent_contact_id '
            . 'WHERE lr.id = :reservation_id AND psl.parent_contact_id = :parent_contact_id '
            . "AND lr.status = 'active' AND lr.expires_at > CURRENT_TIMESTAMP AND pc.active = 1 "
            . 'AND pc.verified_at IS NOT NULL LIMIT 1'
        );
        $statement->execute([
            'reservation_id' => $reservationId,
            'parent_contact_id' => $parent->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Die Reservierung ist nicht mehr gültig oder Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'student_id' => (int) $row['student_id'],
            'school_year_id' => (int) $row['school_year_id'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'starts_on' => (string) $row['starts_on'],
            'current_date' => (string) $row['current_date'],
            'school_year_label' => (string) $row['school_year_label'],
            'locker_short_name' => (string) $row['locker_short_name'],
            'stripe_customer_id' => $row['stripe_customer_id'] !== null
                ? (string) $row['stripe_customer_id']
                : null,
        ];
    }

    /** @return array{months: int, charged_cents: int} */
    private function feeQuote(int $annualFeeCents, string $startsOn, string $currentDate): array
    {
        $period = SchoolYearPeriod::fromStartYear((int) substr($startsOn, 0, 4));
        $bookingDate = new DateTimeImmutable($currentDate);

        return $bookingDate < $period->startsOn
            ? ['months' => 12, 'charged_cents' => $annualFeeCents]
            : $this->fees->prorate($annualFeeCents, $bookingDate, $period);
    }

    private function parentName(AuthenticatedParent $parent): ?string
    {
        $name = trim(($parent->firstName ?? '') . ' ' . ($parent->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    private function assertHttpsBaseUrl(): void
    {
        if (!preg_match('#^https://[^/]+(?:/.*)?$#i', rtrim(trim($this->baseUrl), '/'))) {
            throw new DomainException('Für Stripe muss eine kanonische HTTPS-Basis-URL konfiguriert sein.');
        }
    }
}
