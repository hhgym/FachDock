<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Booking\BookingCreationData;
use FachDock\Booking\BookingService;
use FachDock\Booking\BookingStatus;
use FachDock\Mail\BookingNotificationService;
use PDO;
use RuntimeException;
use Throwable;

final class PaidPaymentRecoveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingService $bookings,
        private readonly ?BookingNotificationService $notifications = null,
    ) {
    }

    public function recover(int $paymentId): int
    {
        if ($paymentId < 1) {
            throw new DomainException('Die Zahlung ist ungültig.');
        }

        $payment = $this->claim($paymentId);
        if ($payment['already_paid']) {
            if ($payment['booking_id'] === null) {
                throw new RuntimeException('Der bezahlten Zahlung fehlt die Buchungsreferenz.');
            }

            return $payment['booking_id'];
        }

        if ($payment['reservation_id'] === null) {
            if ($payment['booking_id'] === null) {
                throw new RuntimeException('Die Zahlung ist weder einer Reservierung noch einer Buchung zugeordnet.');
            }

            try {
                $this->recoverExistingBooking($paymentId, $payment['booking_id']);
                $this->notifications?->bookingConfirmed($payment['booking_id']);

                return $payment['booking_id'];
            } catch (Throwable $exception) {
                $this->restoreManualReview($paymentId, $exception, 'paid_booking_activation_failed');
                throw $exception;
            }
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
            $this->notifications?->bookingConfirmed($bookingId);

            return $bookingId;
        } catch (Throwable $exception) {
            $this->restoreManualReview($paymentId, $exception, 'paid_booking_failed');
            throw $exception;
        }
    }

    /**
     * @return array{
     *     reservation_id: int|null,
     *     parent_contact_id: int,
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     booking_id: int|null,
     *     already_paid: bool
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

                return $this->normalizeClaim($row, true);
            }

            $failureCode = (string) ($row['failure_code'] ?? '');
            if ((string) $row['status'] !== PaymentStatus::ManualReview->value
                || !in_array($failureCode, ['paid_booking_failed', 'paid_booking_activation_failed'], true)
                || $row['paid_at'] === null) {
                throw new DomainException('Diese Zahlung ist nicht für eine automatische Wiederherstellung freigegeben.');
            }

            $update = $this->pdo->prepare(
                "UPDATE payments SET status = 'processing_paid', processing_started_at = CURRENT_TIMESTAMP, "
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute(['id' => $paymentId]);
            $this->pdo->commit();

            return $this->normalizeClaim($row, false);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     reservation_id: int|null,
     *     parent_contact_id: int,
     *     amount_cents: int,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     booking_id: int|null,
     *     already_paid: bool
     * }
     */
    private function normalizeClaim(array $row, bool $alreadyPaid): array
    {
        return [
            'reservation_id' => $row['reservation_id'] === null ? null : (int) $row['reservation_id'],
            'parent_contact_id' => (int) $row['parent_contact_id'],
            'amount_cents' => (int) $row['amount_cents'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'proration_months' => (int) $row['proration_months'],
            'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'already_paid' => $alreadyPaid,
        ];
    }

    private function recoverExistingBooking(int $paymentId, int $bookingId): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT status FROM bookings WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $bookingId]);
            $status = $statement->fetchColumn();
            if (!is_string($status) || !in_array($status, ['payment_due', 'active'], true)) {
                throw new RuntimeException('Die bestehende Buchung kann nicht aktiviert werden.');
            }
            if ($status === 'payment_due') {
                $this->pdo->prepare(
                    "UPDATE bookings SET status = 'active', payment_due_at = NULL, updated_at = CURRENT_TIMESTAMP "
                    . "WHERE id = :id AND status = 'payment_due'"
                )->execute(['id' => $bookingId]);
            }

            $payment = $this->pdo->prepare(
                "UPDATE payments SET status = 'paid', failure_code = NULL, failure_message = NULL, "
                . 'processing_started_at = NULL, paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP), '
                . "updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'processing_paid'"
            );
            $payment->execute(['id' => $paymentId]);
            if ($payment->rowCount() !== 1) {
                throw new RuntimeException('Der bereinigte Zahlungsstatus konnte nicht gespeichert werden.');
            }
            $this->pdo->prepare('DELETE FROM booking_payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);
            $this->pdo->commit();
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

    private function restoreManualReview(int $paymentId, Throwable $exception, string $failureCode): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments SET status = 'manual_review', failure_code = :failure_code, "
            . 'failure_message = :message, processing_started_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status = 'processing_paid'"
        );
        $statement->execute([
            'id' => $paymentId,
            'failure_code' => $failureCode,
            'message' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
