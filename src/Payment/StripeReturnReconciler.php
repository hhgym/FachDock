<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Booking\BookingCreationData;
use FachDock\Booking\BookingService;
use FachDock\Booking\BookingStatus;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use RuntimeException;
use Throwable;

final class StripeReturnReconciler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeCheckoutReader $stripe,
        private readonly BookingService $bookings,
    ) {
    }

    public function reconcile(AuthenticatedParent $parent, int $paymentId, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($paymentId < 1 || $sessionId === '') {
            throw new DomainException('Der Stripe-Zahlungsvorgang ist unvollständig.');
        }

        $payment = $this->paymentForParent($parent->id, $paymentId);
        $this->assertSessionMatches($payment['stripe_checkout_session_id'], $sessionId);

        if (in_array($payment['status'], [
            PaymentStatus::Paid->value,
            PaymentStatus::ManualReview->value,
        ], true)) {
            return;
        }

        $checkout = $this->stripe->retrieveCheckoutState($sessionId);
        if (!hash_equals($sessionId, $checkout->sessionId)) {
            throw new RuntimeException('Stripe hat eine unerwartete Checkout Session zurückgegeben.');
        }
        if ($checkout->paymentStatus !== 'paid') {
            return;
        }

        $claimed = $this->claimPaidPayment($parent->id, $paymentId, $sessionId, $checkout->paymentIntentId);
        if ($claimed === null) {
            return;
        }

        try {
            $bookingId = $this->convertedBookingForReservation($claimed['reservation_id']);
            if ($bookingId === null) {
                $bookingId = $this->bookings->convertReservation(
                    $claimed['reservation_id'],
                    new BookingCreationData(
                        BookingStatus::Active,
                        'parent',
                        $parent->id,
                        $claimed['annual_fee_cents'],
                        $claimed['amount_cents'],
                        $claimed['proration_months'],
                        null,
                    ),
                );
            }
            $this->finalizePaidPayment($paymentId, $bookingId, $checkout->paymentIntentId);
        } catch (Throwable $exception) {
            $this->markManualReview($paymentId, $checkout->paymentIntentId, $exception);
        }
    }

    /**
     * @return array{
     *     reservation_id:int,
     *     status:string,
     *     amount_cents:int,
     *     annual_fee_cents:int,
     *     proration_months:int,
     *     stripe_checkout_session_id:string|null
     * }
     */
    private function paymentForParent(int $parentContactId, int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT reservation_id, status, amount_cents, annual_fee_cents, proration_months, '
            . 'stripe_checkout_session_id FROM payments '
            . 'WHERE id = :id AND parent_contact_id = :parent_contact_id LIMIT 1'
        );
        $statement->execute([
            'id' => $paymentId,
            'parent_contact_id' => $parentContactId,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Diese Zahlung ist Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'reservation_id' => (int) $row['reservation_id'],
            'status' => (string) $row['status'],
            'amount_cents' => (int) $row['amount_cents'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'proration_months' => (int) $row['proration_months'],
            'stripe_checkout_session_id' => $row['stripe_checkout_session_id'] !== null
                ? (string) $row['stripe_checkout_session_id']
                : null,
        ];
    }

    private function assertSessionMatches(?string $storedSessionId, string $sessionId): void
    {
        if ($storedSessionId === null || !hash_equals($storedSessionId, $sessionId)) {
            throw new DomainException('Die Stripe Checkout Session gehört nicht zu dieser Zahlung.');
        }
    }

    /**
     * @return array{reservation_id:int,amount_cents:int,annual_fee_cents:int,proration_months:int}|null
     */
    private function claimPaidPayment(
        int $parentContactId,
        int $paymentId,
        string $sessionId,
        ?string $paymentIntentId,
    ): ?array {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT reservation_id, status, amount_cents, annual_fee_cents, proration_months, '
                . 'stripe_checkout_session_id, processing_started_at FROM payments '
                . 'WHERE id = :id AND parent_contact_id = :parent_contact_id FOR UPDATE'
            );
            $statement->execute([
                'id' => $paymentId,
                'parent_contact_id' => $parentContactId,
            ]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new DomainException('Diese Zahlung ist Ihrem Elternkonto nicht zugeordnet.');
            }
            $storedSessionId = $row['stripe_checkout_session_id'] !== null
                ? (string) $row['stripe_checkout_session_id']
                : null;
            $this->assertSessionMatches($storedSessionId, $sessionId);

            $status = (string) $row['status'];
            if (in_array($status, [PaymentStatus::Paid->value, PaymentStatus::ManualReview->value], true)) {
                $this->pdo->commit();

                return null;
            }
            if ($status === PaymentStatus::ProcessingPaid->value
                && $row['processing_started_at'] !== null
                && strtotime((string) $row['processing_started_at']) > time() - 120) {
                $this->pdo->commit();

                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE payments SET status = 'processing_paid', "
                . 'stripe_payment_intent_id = COALESCE(:intent_id, stripe_payment_intent_id), '
                . 'processing_started_at = CURRENT_TIMESTAMP, failure_code = NULL, failure_message = NULL, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute([
                'id' => $paymentId,
                'intent_id' => $paymentIntentId,
            ]);
            $this->pdo->commit();

            return [
                'reservation_id' => (int) $row['reservation_id'],
                'amount_cents' => (int) $row['amount_cents'],
                'annual_fee_cents' => (int) $row['annual_fee_cents'],
                'proration_months' => (int) $row['proration_months'],
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function finalizePaidPayment(int $paymentId, int $bookingId, ?string $paymentIntentId): void
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
                'intent_id' => $paymentIntentId,
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function markManualReview(int $paymentId, ?string $paymentIntentId, Throwable $exception): void
    {
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
                'intent_id' => $paymentIntentId,
                'message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->pdo->commit();
        } catch (Throwable $innerException) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $innerException;
        }
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
}
