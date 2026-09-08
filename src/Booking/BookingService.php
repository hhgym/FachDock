<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Location\LockerNaming;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class BookingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @throws JsonException */
    public function convertReservation(int $reservationId, BookingCreationData $data): int
    {
        if ($reservationId < 1) {
            throw new DomainException('Die Reservierung ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $reservation = $this->loadConvertibleReservation($reservationId);
            $this->assertStudentHasNoActiveBooking($reservation['student_id'], $reservation['school_year_id']);
            $locker = $this->loadBookableLocker($reservation['locker_id']);
            $this->assertLockerUnoccupied($reservation['school_year_id'], $reservation['locker_id']);
            $student = $this->loadActiveStudent($reservation['student_id']);

            $bookingId = $this->insertBooking($reservation, $student, $data);
            $this->insertBookingSlot($bookingId, $reservation['school_year_id'], $reservation['student_id']);
            $this->insertOccupancy($bookingId, $reservation['school_year_id'], $reservation['locker_id']);
            $this->insertAssignment($bookingId, $reservation, $locker, $data);
            $this->lockCabinetGroup((int) $locker['cabinet_group_id']);

            $this->pdo->prepare('DELETE FROM reservation_slots WHERE reservation_id = :id')
                ->execute(['id' => $reservationId]);
            $this->pdo->prepare(
                "UPDATE locker_reservations SET status = 'converted', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute(['id' => $reservationId]);

            $this->pdo->commit();

            return $bookingId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException(
                    'Die Buchung konnte wegen einer zwischenzeitlichen Belegung nicht abgeschlossen werden.',
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

    /**
     * @return array{
     *     id: int,
     *     student_id: int,
     *     school_year_id: int,
     *     locker_id: int,
     *     projected_grade: int,
     *     rule_snapshot: string,
     *     starts_on: string,
     *     ends_on: string
     * }
     */
    private function loadConvertibleReservation(int $reservationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lr.id, lr.student_id, lr.school_year_id, lr.locker_id, lr.projected_grade, '
            . 'lr.rule_snapshot, sy.starts_on, sy.ends_on '
            . 'FROM locker_reservations lr '
            . 'INNER JOIN reservation_slots rs ON rs.reservation_id = lr.id '
            . 'INNER JOIN school_years sy ON sy.id = lr.school_year_id '
            . 'WHERE lr.id = :id AND ('
            . "(lr.status = 'active' AND lr.expires_at > CURRENT_TIMESTAMP) OR "
            . "(lr.status = 'payment_running' AND lr.payment_grace_expires_at IS NOT NULL "
            . 'AND lr.payment_grace_expires_at > CURRENT_TIMESTAMP)'
            . ') FOR UPDATE'
        );
        $statement->execute(['id' => $reservationId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Die Reservierung ist nicht mehr aktiv oder ihre Frist ist abgelaufen.');
        }

        return [
            'id' => (int) $row['id'],
            'student_id' => (int) $row['student_id'],
            'school_year_id' => (int) $row['school_year_id'],
            'locker_id' => (int) $row['locker_id'],
            'projected_grade' => (int) $row['projected_grade'],
            'rule_snapshot' => (string) $row['rule_snapshot'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
        ];
    }

    private function assertStudentHasNoActiveBooking(int $studentId, int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT booking_id FROM booking_slots '
            . 'WHERE student_id = :student_id AND school_year_id = :school_year_id FOR UPDATE'
        );
        $statement->execute([
            'student_id' => $studentId,
            'school_year_id' => $schoolYearId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Für den Schüler besteht bereits eine aktive Buchung in diesem Schuljahr.');
        }
    }

    private function assertLockerUnoccupied(int $schoolYearId, int $lockerId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT booking_id FROM locker_occupancies '
            . 'WHERE school_year_id = :school_year_id AND locker_id = :locker_id FOR UPDATE'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Das Schließfach wurde zwischenzeitlich vergeben.');
        }
    }

    /** @return array{first_name: string, last_name: string, class_name: string, grade: int} */
    private function loadActiveStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT first_name, last_name, class_name, grade FROM students WHERE id = :id AND active = 1 FOR UPDATE'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Der Schüler ist nicht mehr aktiv.');
        }

        return [
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'class_name' => (string) $row['class_name'],
            'grade' => (int) $row['grade'],
        ];
    }

    /**
     * @return array{
     *     short_name: string,
     *     locker_position: int,
     *     corpus_position: int,
     *     group_code: string,
     *     cabinet_group_id: int,
     *     area_code: string,
     *     area_name: string,
     *     floor_code: string,
     *     floor_name: string,
     *     building_code: string,
     *     building_name: string
     * }
     */
    private function loadBookableLocker(int $lockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.short_name, l.position_no AS locker_position, c.position_no AS corpus_position, '
            . 'cg.id AS cabinet_group_id, cg.code AS group_code, a.code AS area_code, a.name AS area_name, '
            . 'f.code AS floor_code, f.name AS floor_name, b.code AS building_code, b.name AS building_name '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE l.id = :id AND l.active = 1 AND l.bookable = 1 '
            . "AND l.operating_status = 'operational' AND c.active = 1 AND cg.active = 1 "
            . 'AND a.active = 1 AND f.active = 1 AND b.active = 1 FOR UPDATE'
        );
        $statement->execute(['id' => $lockerId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Das reservierte Schließfach ist nicht mehr buchbar.');
        }

        return [
            'short_name' => (string) $row['short_name'],
            'locker_position' => (int) $row['locker_position'],
            'corpus_position' => (int) $row['corpus_position'],
            'group_code' => (string) $row['group_code'],
            'cabinet_group_id' => (int) $row['cabinet_group_id'],
            'area_code' => (string) $row['area_code'],
            'area_name' => (string) $row['area_name'],
            'floor_code' => (string) $row['floor_code'],
            'floor_name' => (string) $row['floor_name'],
            'building_code' => (string) $row['building_code'],
            'building_name' => (string) $row['building_name'],
        ];
    }

    /**
     * @param array{student_id: int, school_year_id: int, locker_id: int, projected_grade: int, rule_snapshot: string, starts_on: string, ends_on: string, id: int} $reservation
     * @param array{first_name: string, last_name: string, class_name: string, grade: int} $student
     * @throws JsonException
     */
    private function insertBooking(array $reservation, array $student, BookingCreationData $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings '
            . '(student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, '
            . 'initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, '
            . 'previous_booking_id, student_snapshot, rule_snapshot, created_at, updated_at) '
            . 'VALUES (:student_id, :school_year_id, :status, :projected_grade, :valid_from, :valid_until, '
            . ':initiated_by_type, :initiated_by_id, :annual_fee_cents, :charged_fee_cents, :proration_months, '
            . ':fee_exemption_type, :previous_booking_id, :student_snapshot, :rule_snapshot, '
            . 'CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'student_id' => $reservation['student_id'],
            'school_year_id' => $reservation['school_year_id'],
            'status' => $data->status->value,
            'projected_grade' => $reservation['projected_grade'],
            'valid_from' => $reservation['starts_on'],
            'valid_until' => $reservation['ends_on'],
            'initiated_by_type' => trim($data->initiatedByType),
            'initiated_by_id' => $data->initiatedById,
            'annual_fee_cents' => $data->annualFeeCents,
            'charged_fee_cents' => $data->chargedFeeCents,
            'proration_months' => $data->prorationMonths,
            'fee_exemption_type' => $this->nullable($data->feeExemptionType),
            'previous_booking_id' => $data->previousBookingId,
            'student_snapshot' => json_encode($student, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'rule_snapshot' => $reservation['rule_snapshot'],
        ]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Die Buchung konnte nicht angelegt werden.');
        }

        return $id;
    }

    private function insertBookingSlot(int $bookingId, int $schoolYearId, int $studentId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) '
            . 'VALUES (:booking_id, :school_year_id, :student_id, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'school_year_id' => $schoolYearId,
            'student_id' => $studentId,
        ]);
    }

    private function insertOccupancy(int $bookingId, int $schoolYearId, int $lockerId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) '
            . 'VALUES (:school_year_id, :locker_id, :booking_id, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
            'booking_id' => $bookingId,
        ]);
    }

    /**
     * @param array{school_year_id: int, locker_id: int, student_id: int, projected_grade: int, rule_snapshot: string, starts_on: string, ends_on: string, id: int} $reservation
     * @param array{short_name: string, locker_position: int, corpus_position: int, group_code: string, cabinet_group_id: int, area_code: string, area_name: string, floor_code: string, floor_name: string, building_code: string, building_name: string} $locker
     * @throws JsonException
     */
    private function insertAssignment(
        int $bookingId,
        array $reservation,
        array $locker,
        BookingCreationData $data,
    ): void {
        $longName = LockerNaming::longName(
            $locker['floor_code'],
            $locker['area_code'],
            $locker['group_code'],
            $locker['corpus_position'],
            $locker['locker_position'],
        );
        $snapshot = [
            'short_name' => $locker['short_name'],
            'long_name' => $longName,
            'building' => ['code' => $locker['building_code'], 'name' => $locker['building_name']],
            'floor' => ['code' => $locker['floor_code'], 'name' => $locker['floor_name']],
            'area' => ['code' => $locker['area_code'], 'name' => $locker['area_name']],
            'cabinet_group' => ['code' => $locker['group_code']],
            'corpus_position' => $locker['corpus_position'],
            'locker_position' => $locker['locker_position'],
        ];

        $statement = $this->pdo->prepare(
            'INSERT INTO locker_assignment_history '
            . '(booking_id, school_year_id, locker_id, starts_at, reason, actor_type, actor_id, '
            . 'locker_snapshot, created_at) '
            . 'VALUES (:booking_id, :school_year_id, :locker_id, CURRENT_TIMESTAMP, :reason, '
            . ':actor_type, :actor_id, :locker_snapshot, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'school_year_id' => $reservation['school_year_id'],
            'locker_id' => $reservation['locker_id'],
            'reason' => trim($data->assignmentReason),
            'actor_type' => trim($data->initiatedByType),
            'actor_id' => $data->initiatedById,
            'locker_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function lockCabinetGroup(int $cabinetGroupId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cabinet_groups SET structure_locked_at = COALESCE(structure_locked_at, CURRENT_TIMESTAMP), '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $cabinetGroupId]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM cabinet_groups WHERE id = :id');
            $exists->execute(['id' => $cabinetGroupId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Die Schrankgruppe der Buchung existiert nicht mehr.');
            }
        }
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
