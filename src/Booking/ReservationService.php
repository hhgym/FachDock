<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DateTimeImmutable;
use DomainException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ReservationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $reservationMinutes = 15,
        private readonly int $paymentGraceMinutes = 30,
    ) {
    }

    public function reserve(
        int $studentId,
        int $schoolYearId,
        int $lockerId,
        bool $allowBeforeOpening = false,
    ): int {
        if ($studentId < 1 || $schoolYearId < 1 || $lockerId < 1) {
            throw new DomainException('Schüler, Schuljahr und Schließfach müssen gültig sein.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->expireStaleWithinTransaction();
            $this->assertActiveStudent($studentId);
            $this->assertBookableSchoolYear($schoolYearId, $allowBeforeOpening);
            $this->assertBookableLocker($schoolYearId, $lockerId);
            $this->replaceStudentReservationIfAllowed($studentId, $schoolYearId);
            $this->assertLockerHasNoReservation($lockerId, $schoolYearId);

            $duration = max(1, $this->reservationMinutes);
            $statement = $this->pdo->prepare(
                'INSERT INTO locker_reservations '
                . '(student_id, school_year_id, locker_id, status, expires_at, created_at, updated_at) '
                . "VALUES (:student_id, :school_year_id, :locker_id, 'active', "
                . 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $duration . ' MINUTE), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'student_id' => $studentId,
                'school_year_id' => $schoolYearId,
                'locker_id' => $lockerId,
            ]);

            $reservationId = (int) $this->pdo->lastInsertId();
            if ($reservationId < 1) {
                throw new RuntimeException('Die Reservierung konnte nicht angelegt werden.');
            }

            $slot = $this->pdo->prepare(
                'INSERT INTO reservation_slots '
                . '(reservation_id, school_year_id, student_id, locker_id, created_at) '
                . 'VALUES (:reservation_id, :school_year_id, :student_id, :locker_id, CURRENT_TIMESTAMP)'
            );
            $slot->execute([
                'reservation_id' => $reservationId,
                'school_year_id' => $schoolYearId,
                'student_id' => $studentId,
                'locker_id' => $lockerId,
            ]);

            $this->pdo->commit();

            return $reservationId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException(
                    'Das Schließfach oder der Reservierungsplatz wurde gerade anderweitig vergeben. Bitte neu auswählen.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function startPayment(int $reservationId, int $studentId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->expireStaleWithinTransaction();
            $statement = $this->pdo->prepare(
                'SELECT id FROM locker_reservations '
                . "WHERE id = :reservation_id AND student_id = :student_id AND status = 'active' "
                . 'AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $statement->execute([
                'reservation_id' => $reservationId,
                'student_id' => $studentId,
            ]);
            if ($statement->fetchColumn() === false) {
                throw new DomainException('Die Reservierung ist nicht mehr für eine Zahlung verfügbar.');
            }

            $grace = max(1, $this->paymentGraceMinutes);
            $update = $this->pdo->prepare(
                "UPDATE locker_reservations SET status = 'payment_running', "
                . 'payment_grace_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $grace . ' MINUTE), '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute(['id' => $reservationId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancel(int $reservationId, int $studentId): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT status FROM locker_reservations '
                . 'WHERE id = :reservation_id AND student_id = :student_id FOR UPDATE'
            );
            $statement->execute([
                'reservation_id' => $reservationId,
                'student_id' => $studentId,
            ]);
            $status = $statement->fetchColumn();
            if ($status === false) {
                throw new DomainException('Die Reservierung existiert nicht.');
            }
            if ((string) $status === ReservationStatus::Converted->value) {
                throw new DomainException('Eine bereits gebuchte Reservierung kann nicht storniert werden.');
            }

            $this->pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id')
                ->execute(['id' => $reservationId]);
            $this->pdo->prepare(
                "UPDATE locker_reservations SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute(['id' => $reservationId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function expireStale(): int
    {
        $this->pdo->beginTransaction();
        try {
            $expired = $this->expireStaleWithinTransaction();
            $this->pdo->commit();

            return $expired;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function expireStaleWithinTransaction(): int
    {
        $condition = "(lr.status = 'active' AND lr.expires_at <= CURRENT_TIMESTAMP) "
            . "OR (lr.status = 'payment_running' AND lr.payment_grace_expires_at IS NOT NULL "
            . 'AND lr.payment_grace_expires_at <= CURRENT_TIMESTAMP)';

        $this->pdo->exec(
            'DELETE rs FROM reservation_slots rs INNER JOIN locker_reservations lr '
            . 'ON lr.id = rs.reservation_id WHERE ' . $condition
        );
        $updated = $this->pdo->exec(
            "UPDATE locker_reservations lr SET lr.status = 'expired', lr.updated_at = CURRENT_TIMESTAMP WHERE "
            . $condition
        );

        return $updated === false ? 0 : $updated;
    }

    private function assertActiveStudent(int $studentId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM students WHERE id = :id AND active = 1 FOR UPDATE');
        $statement->execute(['id' => $studentId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Der Schüler ist nicht für eine Buchung verfügbar.');
        }
    }

    private function assertBookableSchoolYear(int $schoolYearId, bool $allowBeforeOpening): void
    {
        $statement = $this->pdo->prepare(
            'SELECT status, new_booking_opens_on FROM school_years WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Das Schuljahr existiert nicht.');
        }
        if ((string) $row['status'] === 'closed') {
            throw new DomainException('Das Schuljahr ist bereits geschlossen.');
        }
        if (!$allowBeforeOpening) {
            $opensOn = new DateTimeImmutable((string) $row['new_booking_opens_on']);
            if (new DateTimeImmutable('today') < $opensOn) {
                throw new DomainException('Reguläre Neubuchungen für dieses Schuljahr sind noch nicht geöffnet.');
            }
        }
    }

    private function assertBookableLocker(int $schoolYearId, int $lockerId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id FROM lockers l '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE l.id = :locker_id AND l.active = 1 AND l.bookable = 1 '
            . "AND l.operating_status = 'operational' AND c.active = 1 AND cg.active = 1 "
            . 'AND a.active = 1 AND f.active = 1 AND b.active = 1 FOR UPDATE'
        );
        $statement->execute(['locker_id' => $lockerId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Das Schließfach ist nicht buchbar.');
        }

        $occupancy = $this->pdo->prepare(
            'SELECT locker_id FROM locker_occupancies '
            . 'WHERE school_year_id = :school_year_id AND locker_id = :locker_id FOR UPDATE'
        );
        $occupancy->execute([
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
        ]);
        if ($occupancy->fetchColumn() !== false) {
            throw new DomainException('Das Schließfach ist für dieses Schuljahr bereits vergeben.');
        }
    }

    private function replaceStudentReservationIfAllowed(int $studentId, int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT rs.reservation_id, lr.status FROM reservation_slots rs '
            . 'INNER JOIN locker_reservations lr ON lr.id = rs.reservation_id '
            . 'WHERE rs.school_year_id = :school_year_id AND rs.student_id = :student_id FOR UPDATE'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'student_id' => $studentId,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return;
        }
        if ((string) $row['status'] === ReservationStatus::PaymentRunning->value) {
            throw new DomainException('Für den Schüler läuft bereits ein Zahlungsvorgang.');
        }

        $reservationId = (int) $row['reservation_id'];
        $this->pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id')
            ->execute(['id' => $reservationId]);
        $this->pdo->prepare(
            "UPDATE locker_reservations SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        )->execute(['id' => $reservationId]);
    }

    private function assertLockerHasNoReservation(int $lockerId, int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT reservation_id FROM reservation_slots '
            . 'WHERE school_year_id = :school_year_id AND locker_id = :locker_id FOR UPDATE'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Das Schließfach ist momentan reserviert.');
        }
    }
}
