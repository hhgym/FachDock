<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use PDO;
use Throwable;

final class AdminPaymentTerminationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function terminate(int $paymentId, string $reason, string $actorName): void
    {
        $reason = trim($reason);
        if ($paymentId < 1) {
            throw new DomainException('Die Zahlung ist ungültig.');
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new DomainException('Bitte einen Grund mit 3 bis 500 Zeichen angeben.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, reservation_id, status FROM payments WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $paymentId]);
            $payment = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($payment)) {
                throw new DomainException('Der Zahlungsvorgang wurde nicht gefunden.');
            }
            if (!in_array((string) $payment['status'], [
                PaymentStatus::Creating->value,
                PaymentStatus::CheckoutOpen->value,
            ], true)) {
                throw new DomainException(
                    'Nur noch nicht bestätigte offene Zahlungsvorgänge können manuell beendet werden.'
                );
            }

            $message = 'Manuell beendet durch ' . trim($actorName) . ': ' . $reason;
            $update = $this->pdo->prepare(
                "UPDATE payments SET status = 'expired', checkout_url = NULL, "
                . "failure_code = 'admin_terminated', failure_message = :message, processing_started_at = NULL, "
                . 'failed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute([
                'id' => $paymentId,
                'message' => mb_substr($message, 0, 1000),
            ]);
            $this->pdo->prepare('DELETE FROM payment_attempt_slots WHERE payment_id = :id')
                ->execute(['id' => $paymentId]);

            $reservationId = (int) $payment['reservation_id'];
            $reservation = $this->pdo->prepare(
                'SELECT status, (expires_at > CURRENT_TIMESTAMP) AS original_active '
                . 'FROM locker_reservations WHERE id = :id FOR UPDATE'
            );
            $reservation->execute(['id' => $reservationId]);
            $row = $reservation->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && (string) $row['status'] === 'payment_running') {
                if ((int) $row['original_active'] === 1) {
                    $this->pdo->prepare(
                        "UPDATE locker_reservations SET status = 'active', payment_grace_expires_at = NULL, "
                        . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                    )->execute(['id' => $reservationId]);
                } else {
                    $this->pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id')
                        ->execute(['id' => $reservationId]);
                    $this->pdo->prepare(
                        "UPDATE locker_reservations SET status = 'expired', payment_grace_expires_at = NULL, "
                        . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                    )->execute(['id' => $reservationId]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
