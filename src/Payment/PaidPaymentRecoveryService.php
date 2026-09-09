<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Booking\BookingCreationData;
use FachDock\Booking\BookingService;
use FachDock\Booking\BookingStatus;
use PDO;
use RuntimeException;
use Throwable;

final class PaidPaymentRecoveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingService $bookings,
    ) {
    }

    public function recover(int $paymentId): int
    {
        if ($paymentId < 1) {
            throw new DomainException('Die Zahlung ist ungültig.');
        }

        $payment = $this->claim($paymentId);
        if ($payment['booking_id'] !== null) {
            return $payment['booking_id'];
        }

        try {
            $bookingId = $this->convertedBookingForReservation($payment['reservation_id']);
            if ($bookingId === null) {
                $this->extendPaymentGrace($payment['reservation_id']);
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

            $statement = $this->pdo->prepare(
                "UPDATE payments SET status = 'paid', booking_id = :booking_id, checkout_url = NULL, "
                . 'failure_code = NULL, failure_message = NULL, processing_started_at = NULL, '
                . 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP '
                . "WHERE id = :id AND status = 'processing_paid'"
            );
            $statement->execute([
                'id' => $paymentId,
                'booking_id' => $bookingId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Der bereinigte Zahlungsstatus konnte nicht gespeichert werden.');
            }

            return $bookingId;
        } catch (Throwable $exception) {
            $this->restoreManualReview($paymentId, $exception);
            throw $exception;
        }
    }

    /**
     * @return array{
     *     reservation_id: int,
     *     parent_contact_id: int,
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     booking_id: int|null
     * }
     */
    private function claim(int $paymentId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT reservation_id, parent_contact_id, status, amount_cents, annual_fee_cents, '
                . 'proration_months, failure_code, paid_at, booking_id '
                . 'FROM payments WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $paymentId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new DomainException('Die Zahlung wurde nicht gefunden.');
            }

            if ((string) $row['status'] === PaymentStatus::Paid->value && $row['booking_id'] !== null) {
                $this->pdo->commit();

                return [
                    'reservation_id' => (int) $row['reservation_id'],
                    'parent_contact_id' => (int) $row['parent_contact_id'],
                    'amount_cents' => (int) $row['amount_cents'],
                    'annual_fee_cents' => (int) $row['annual_fee_cents'],
                    'proration_months' => (int) $row['proration_months'],
                    'booking_id' => (int) $row['booking_id'],
                ];
            }

            if ((string) $row['status'] !== PaymentStatus::ManualReview->value
                || (string) ($row['failure_code'] ?? '') !== 'paid_booking_failed'
                || $row['paid_at'] === null) {
                throw new DomainException('Diese Zahlung ist nicht für eine automatische Wiederherstellung freigegeben.');
            }

            $update = $this->pdo->prepare(
                "UPDATE payments SET status = 'processing_paid', processing_started_at = CURRENT_TIMESTAMP, "
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute(['id' => $paymentId]);
            $this->pdo->commit();

            return [
                'reservation_id' => (int) $row['reservation_id'],
                'parent_contact_id' => (int) $row['parent_contact_id'],
                'amount_cents' => (int) $row['amount_cents'],
                'annual_fee_cents' => (int) $row['annual_fee_cents'],
                'proration_months' => (int) $row['proration_months'],
                'booking_id' => null,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function extendPaymentGrace(int $reservationId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE locker_reservations SET payment_grace_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE), '
            . 'updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status = 'payment_running'"
        );
        $statement->execute(['id' => $reservationId]);
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

    private function restoreManualReview(int $paymentId, Throwable $exception): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments SET status = 'manual_review', failure_code = 'paid_booking_failed', "
            . 'failure_message = :message, processing_started_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status = 'processing_paid'"
        );
        $statement->execute([
            'id' => $paymentId,
            'message' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
