<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Location\LockerNaming;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class BookingLifecycleService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $allocationRules,
        private readonly int $renewalPaymentDays = 14,
    ) {
    }

    public function changeLocker(
        AuthenticatedStaff $staff,
        int $bookingId,
        int $newLockerId,
        string $reason,
    ): void {
        $reason = $this->reason($reason);
        if ($bookingId < 1 || $newLockerId < 1) {
            throw new DomainException('Buchung oder Schließfach ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $booking = $this->activeLifecycleBooking($bookingId);
            $this->assertNoInFlightPayment($bookingId);
            $oldLockerId = $booking['locker_id'];
            if ($oldLockerId === $newLockerId) {
                throw new DomainException('Das neue Schließfach entspricht dem aktuell zugeordneten Schließfach.');
            }

            $newLocker = $this->bookableLocker($newLockerId);
            $this->assertLockerAvailable($booking['school_year_id'], $newLockerId);
            $decision = $this->allocationRules->evaluate(
                $booking['school_year_id'],
                $booking['projected_grade'],
                $newLockerId,
            );
            if (!$decision->allowed) {
                throw new DomainException($decision->reason ?? 'Das Schließfach ist nach den Zuteilungsregeln nicht zulässig.');
            }

            $this->pdo->prepare('DELETE FROM locker_occupancies WHERE booking_id = :booking_id')
                ->execute(['booking_id' => $bookingId]);
            $this->pdo->prepare(
                'INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) '
                . 'VALUES (:school_year_id, :locker_id, :booking_id, CURRENT_TIMESTAMP)'
            )->execute([
                'school_year_id' => $booking['school_year_id'],
                'locker_id' => $newLockerId,
                'booking_id' => $bookingId,
            ]);

            $this->closeCurrentAssignment($bookingId);
            $this->insertAssignment(
                $bookingId,
                $booking['school_year_id'],
                $newLockerId,
                $newLocker,
                'locker_change',
                $staff->id,
            );
            $this->lockCabinetGroup($newLocker['cabinet_group_id']);

            $this->pdo->prepare(
                'UPDATE bookings SET rule_snapshot = :rule_snapshot, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'id' => $bookingId,
                'rule_snapshot' => json_encode($decision->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->insertEvent(
                $bookingId,
                'locker_changed',
                null,
                $oldLockerId,
                $newLockerId,
                null,
                $reason,
                $staff->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function end(
        AuthenticatedStaff $staff,
        int $bookingId,
        string $reason,
        bool $cancelled = false,
    ): void {
        $reason = $this->reason($reason);
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $booking = $this->activeLifecycleBooking($bookingId);
            $this->assertNoInFlightPayment($bookingId);
            $status = $cancelled ? BookingStatus::Cancelled->value : BookingStatus::Ended->value;
            $eventType = $cancelled ? 'cancelled' : 'ended';

            $this->pdo->prepare('DELETE FROM booking_slots WHERE booking_id = :booking_id')
                ->execute(['booking_id' => $bookingId]);
            $this->pdo->prepare('DELETE FROM locker_occupancies WHERE booking_id = :booking_id')
                ->execute(['booking_id' => $bookingId]);
            $this->closeCurrentAssignment($bookingId);
            $this->cancelPendingPaymentMails($bookingId);

            $validityUpdate = $cancelled
                ? ''
                : 'valid_until = GREATEST(valid_from, LEAST(valid_until, CURRENT_DATE)), ';
            $this->pdo->prepare(
                'UPDATE bookings SET status = :status, ' . $validityUpdate
                . 'payment_due_at = NULL, ended_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id'
            )->execute([
                'id' => $bookingId,
                'status' => $status,
            ]);
            $this->insertEvent(
                $bookingId,
                $eventType,
                null,
                $booking['locker_id'],
                null,
                $this->currentDatabaseDate(),
                $reason,
                $staff->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @throws JsonException */
    public function renew(
        AuthenticatedStaff $staff,
        int $bookingId,
        int $targetSchoolYearId,
        bool $requestBut,
    ): int {
        if ($bookingId < 1 || $targetSchoolYearId < 1) {
            throw new DomainException('Buchung oder Zielschuljahr ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $source = $this->renewableBooking($bookingId);
            $targetYear = $this->targetSchoolYear($targetSchoolYearId);
            if ($targetYear['starts_on'] <= $source['school_year_starts_on']) {
                throw new DomainException('Eine Verlängerung ist nur in ein späteres Schuljahr möglich.');
            }

            $yearDelta = (int) substr($targetYear['starts_on'], 0, 4)
                - (int) substr($source['school_year_starts_on'], 0, 4);
            $projectedGrade = $source['projected_grade'] + $yearDelta;
            if ($yearDelta < 1 || $projectedGrade > 12) {
                throw new DomainException('Für das gewählte Zielschuljahr ist keine reguläre Klassenstufe mehr ableitbar.');
            }

            $this->assertStudentYearAvailable($source['student_id'], $targetSchoolYearId);
            $this->assertLockerAvailable($targetSchoolYearId, $source['locker_id']);
            $locker = $this->bookableLocker($source['locker_id']);
            $decision = $this->allocationRules->evaluate($targetSchoolYearId, $projectedGrade, $source['locker_id']);
            if (!$decision->allowed) {
                throw new DomainException(
                    $decision->reason ?? 'Das bisherige Schließfach ist im Zielschuljahr nach den Zuteilungsregeln nicht zulässig.',
                );
            }

            $studentSnapshot = $this->studentSnapshot($source['student_id']);
            $annualFee = $targetYear['annual_fee_cents'];
            $status = $annualFee === 0
                ? BookingStatus::Active
                : ($requestBut ? BookingStatus::ExemptionReview : BookingStatus::PaymentDue);
            $feeExemption = $requestBut && $annualFee > 0 ? 'but_pending' : null;
            $chargedFee = $status === BookingStatus::PaymentDue ? $annualFee : 0;
            $initiatedByType = $source['initiated_by_type'] === 'parent' ? 'parent' : 'staff';
            $initiatedById = $source['initiated_by_type'] === 'parent'
                ? $source['initiated_by_id']
                : $staff->id;
            $paymentDays = max(1, min(365, $this->renewalPaymentDays));
            $paymentDueExpression = $status === BookingStatus::PaymentDue
                ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $paymentDays . ' DAY)'
                : 'NULL';

            $statement = $this->pdo->prepare(
                'INSERT INTO bookings '
                . '(student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, '
                . 'initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, '
                . 'previous_booking_id, student_snapshot, rule_snapshot, created_at, updated_at, payment_due_at) '
                . 'VALUES (:student_id, :school_year_id, :status, :projected_grade, :valid_from, :valid_until, '
                . ':initiated_by_type, :initiated_by_id, :annual_fee_cents, :charged_fee_cents, 12, :fee_exemption_type, '
                . ':previous_booking_id, :student_snapshot, :rule_snapshot, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '
                . $paymentDueExpression . ')'
            );
            $statement->execute([
                'student_id' => $source['student_id'],
                'school_year_id' => $targetSchoolYearId,
                'status' => $status->value,
                'projected_grade' => $projectedGrade,
                'valid_from' => $targetYear['starts_on'],
                'valid_until' => $targetYear['ends_on'],
                'initiated_by_type' => $initiatedByType,
                'initiated_by_id' => $initiatedById,
                'annual_fee_cents' => $annualFee,
                'charged_fee_cents' => $chargedFee,
                'fee_exemption_type' => $feeExemption,
                'previous_booking_id' => $bookingId,
                'student_snapshot' => json_encode($studentSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'rule_snapshot' => json_encode($decision->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $newBookingId = (int) $this->pdo->lastInsertId();
            if ($newBookingId < 1) {
                throw new RuntimeException('Die Verlängerungsbuchung konnte nicht angelegt werden.');
            }

            $this->pdo->prepare(
                'INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) '
                . 'VALUES (:booking_id, :school_year_id, :student_id, CURRENT_TIMESTAMP)'
            )->execute([
                'booking_id' => $newBookingId,
                'school_year_id' => $targetSchoolYearId,
                'student_id' => $source['student_id'],
            ]);
            $this->pdo->prepare(
                'INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) '
                . 'VALUES (:school_year_id, :locker_id, :booking_id, CURRENT_TIMESTAMP)'
            )->execute([
                'school_year_id' => $targetSchoolYearId,
                'locker_id' => $source['locker_id'],
                'booking_id' => $newBookingId,
            ]);
            $this->insertAssignment(
                $newBookingId,
                $targetSchoolYearId,
                $source['locker_id'],
                $locker,
                'renewal',
                $staff->id,
            );
            $this->lockCabinetGroup($locker['cabinet_group_id']);
            $this->insertEvent(
                $bookingId,
                'renewed',
                $newBookingId,
                $source['locker_id'],
                $source['locker_id'],
                $targetYear['starts_on'],
                'Verlängerung in das Schuljahr ' . $targetYear['label'],
                $staff->id,
            );
            $this->pdo->commit();

            return $newBookingId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{student_id:int,school_year_id:int,projected_grade:int,locker_id:int}
     */
    private function activeLifecycleBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.student_id, b.school_year_id, b.projected_grade, lo.locker_id, b.status '
            . 'FROM bookings b INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'WHERE b.id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Die Buchung besitzt keine aktive Schließfachzuordnung.');
        }
        if (!in_array((string) $row['status'], [
            BookingStatus::Active->value,
            BookingStatus::ExemptionReview->value,
            BookingStatus::PaymentDue->value,
        ], true)) {
            throw new DomainException('Die Buchung ist bereits beendet oder storniert.');
        }

        return [
            'student_id' => (int) $row['student_id'],
            'school_year_id' => (int) $row['school_year_id'],
            'projected_grade' => (int) $row['projected_grade'],
            'locker_id' => (int) $row['locker_id'],
        ];
    }

    /**
     * @return array{student_id:int,projected_grade:int,locker_id:int,school_year_starts_on:string,initiated_by_type:string,initiated_by_id:int|null}
     */
    private function renewableBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.student_id, b.projected_grade, b.initiated_by_type, b.initiated_by_id, '
            . 'sy.starts_on AS school_year_starts_on, lo.locker_id '
            . 'FROM bookings b INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . "WHERE b.id = :id AND b.status = 'active' FOR UPDATE"
        );
        $statement->execute(['id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Nur eine aktive Buchung mit Schließfach kann verlängert werden.');
        }

        return [
            'student_id' => (int) $row['student_id'],
            'projected_grade' => (int) $row['projected_grade'],
            'locker_id' => (int) $row['locker_id'],
            'school_year_starts_on' => (string) $row['school_year_starts_on'],
            'initiated_by_type' => (string) $row['initiated_by_type'],
            'initiated_by_id' => $row['initiated_by_id'] === null ? null : (int) $row['initiated_by_id'],
        ];
    }

    /** @return array{label:string,starts_on:string,ends_on:string,annual_fee_cents:int} */
    private function targetSchoolYear(int $schoolYearId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT label, starts_on, ends_on, annual_fee_cents FROM school_years WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Das Zielschuljahr existiert nicht.');
        }

        return [
            'label' => (string) $row['label'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
        ];
    }

    private function assertStudentYearAvailable(int $studentId, int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT booking_id FROM booking_slots WHERE student_id = :student_id AND school_year_id = :school_year_id FOR UPDATE'
        );
        $statement->execute(['student_id' => $studentId, 'school_year_id' => $schoolYearId]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Für den Schüler besteht im Zielschuljahr bereits eine Buchung.');
        }
    }

    private function assertLockerAvailable(int $schoolYearId, int $lockerId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT booking_id FROM locker_occupancies '
            . 'WHERE school_year_id = :school_year_id AND locker_id = :locker_id FOR UPDATE'
        );
        $statement->execute(['school_year_id' => $schoolYearId, 'locker_id' => $lockerId]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Das Schließfach ist im gewählten Schuljahr bereits belegt.');
        }
    }

    private function assertNoInFlightPayment(int $bookingId): void
    {
        $slot = $this->pdo->prepare(
            'SELECT payment_id FROM booking_payment_attempt_slots WHERE booking_id = :booking_id FOR UPDATE'
        );
        $slot->execute(['booking_id' => $bookingId]);
        if ($slot->fetchColumn() !== false) {
            throw new DomainException('Während eines laufenden Zahlungsvorgangs kann die Buchung nicht geändert werden.');
        }

        $payment = $this->pdo->prepare(
            "SELECT id FROM payments WHERE booking_id = :booking_id AND status IN ('processing_paid','manual_review') LIMIT 1 FOR UPDATE"
        );
        $payment->execute(['booking_id' => $bookingId]);
        if ($payment->fetchColumn() !== false) {
            throw new DomainException('Die Buchung besitzt eine Zahlung in technischer oder manueller Prüfung.');
        }
    }

    /** @return array<string, mixed> */
    private function studentSnapshot(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT first_name, last_name, class_name, grade FROM students WHERE id = :id AND active = 1 FOR UPDATE'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
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
     * @return array{short_name:string,locker_position:int,corpus_position:int,cabinet_group_id:int,group_code:string,area_code:string,area_name:string,floor_code:string,floor_name:string,building_code:string,building_name:string}
     */
    private function bookableLocker(int $lockerId): array
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
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Das Schließfach ist nicht buchbar.');
        }

        return [
            'short_name' => (string) $row['short_name'],
            'locker_position' => (int) $row['locker_position'],
            'corpus_position' => (int) $row['corpus_position'],
            'cabinet_group_id' => (int) $row['cabinet_group_id'],
            'group_code' => (string) $row['group_code'],
            'area_code' => (string) $row['area_code'],
            'area_name' => (string) $row['area_name'],
            'floor_code' => (string) $row['floor_code'],
            'floor_name' => (string) $row['floor_name'],
            'building_code' => (string) $row['building_code'],
            'building_name' => (string) $row['building_name'],
        ];
    }

    /** @param array<string, mixed> $locker */
    private function insertAssignment(
        int $bookingId,
        int $schoolYearId,
        int $lockerId,
        array $locker,
        string $reason,
        int $staffUserId,
    ): void {
        $snapshot = [
            'short_name' => $locker['short_name'],
            'long_name' => LockerNaming::longName(
                (string) $locker['floor_code'],
                (string) $locker['area_code'],
                (string) $locker['group_code'],
                (int) $locker['corpus_position'],
                (int) $locker['locker_position'],
            ),
            'building' => ['code' => $locker['building_code'], 'name' => $locker['building_name']],
            'floor' => ['code' => $locker['floor_code'], 'name' => $locker['floor_name']],
            'area' => ['code' => $locker['area_code'], 'name' => $locker['area_name']],
            'cabinet_group' => ['code' => $locker['group_code']],
            'corpus_position' => $locker['corpus_position'],
            'locker_position' => $locker['locker_position'],
        ];

        $this->pdo->prepare(
            'INSERT INTO locker_assignment_history '
            . '(booking_id, school_year_id, locker_id, starts_at, reason, actor_type, actor_id, locker_snapshot, created_at) '
            . "VALUES (:booking_id, :school_year_id, :locker_id, CURRENT_TIMESTAMP, :reason, 'staff', :actor_id, "
            . ':locker_snapshot, CURRENT_TIMESTAMP)'
        )->execute([
            'booking_id' => $bookingId,
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
            'reason' => $reason,
            'actor_id' => $staffUserId,
            'locker_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function closeCurrentAssignment(int $bookingId): void
    {
        $this->pdo->prepare(
            'UPDATE locker_assignment_history SET ends_at = CURRENT_TIMESTAMP '
            . 'WHERE booking_id = :booking_id AND ends_at IS NULL'
        )->execute(['booking_id' => $bookingId]);
    }

    private function lockCabinetGroup(int $cabinetGroupId): void
    {
        $this->pdo->prepare(
            'UPDATE cabinet_groups SET structure_locked_at = COALESCE(structure_locked_at, CURRENT_TIMESTAMP), '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $cabinetGroupId]);
    }

    private function cancelPendingPaymentMails(int $bookingId): void
    {
        $this->pdo->prepare(
            'UPDATE mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id '
            . "SET q.status = 'canceled', q.canceled_at = CURRENT_TIMESTAMP, q.updated_at = CURRENT_TIMESTAMP "
            . "WHERE q.relation_type = 'booking' AND q.relation_id = :booking_id AND q.status = 'waiting' "
            . "AND t.template_key IN ('payment_due_reminder','but_rejected_payment_due')"
        )->execute(['booking_id' => $bookingId]);
    }

    private function insertEvent(
        int $bookingId,
        string $eventType,
        ?int $relatedBookingId,
        ?int $oldLockerId,
        ?int $newLockerId,
        ?string $effectiveOn,
        string $reason,
        int $staffUserId,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO booking_lifecycle_events '
            . '(booking_id, event_type, related_booking_id, old_locker_id, new_locker_id, effective_on, reason, '
            . 'actor_type, actor_id, created_at) VALUES (:booking_id, :event_type, :related_booking_id, '
            . ":old_locker_id, :new_locker_id, :effective_on, :reason, 'staff', :actor_id, CURRENT_TIMESTAMP)"
        )->execute([
            'booking_id' => $bookingId,
            'event_type' => $eventType,
            'related_booking_id' => $relatedBookingId,
            'old_locker_id' => $oldLockerId,
            'new_locker_id' => $newLockerId,
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'actor_id' => $staffUserId,
        ]);
    }

    private function currentDatabaseDate(): string
    {
        $statement = $this->pdo->query('SELECT CURRENT_DATE');
        if ($statement === false) {
            throw new RuntimeException('Das aktuelle Datenbankdatum konnte nicht ermittelt werden.');
        }
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Das aktuelle Datenbankdatum konnte nicht ermittelt werden.');
        }

        return $value;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Für die Änderung ist eine Begründung erforderlich.');
        }

        return mb_substr($reason, 0, 1000);
    }
}
