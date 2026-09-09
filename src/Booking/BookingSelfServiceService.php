<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Location\LockerNaming;
use FachDock\Parent\AuthenticatedParent;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class BookingSelfServiceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $allocationRules,
        private readonly int $renewalPaymentDays = 14,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function bookingsForParent(AuthenticatedParent $parent, int $changeLimit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.student_id, b.school_year_id, b.status, b.projected_grade, '
            . 's.first_name, s.last_name, s.class_name, sy.label AS school_year_label, sy.starts_on, sy.ends_on, '
            . 'lo.locker_id, l.short_name AS locker_short_name, bu.name AS building_name, f.name AS floor_name, '
            . 'a.name AS area_name '
            . 'FROM bookings b '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = b.student_id '
            . 'INNER JOIN students s ON s.id = b.student_id AND s.active = 1 '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'WHERE psl.parent_contact_id = :parent_contact_id '
            . "AND b.status IN ('active','exemption_review','payment_due') "
            . 'ORDER BY sy.starts_on DESC, s.last_name, s.first_name, b.id DESC'
        );
        $statement->execute(['parent_contact_id' => $parent->id]);

        $limit = max(0, min(20, $changeLimit));
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bookingId = (int) $row['booking_id'];
            $used = $this->parentChangeCount($bookingId);
            $result[] = [
                'booking_id' => $bookingId,
                'student_id' => (int) $row['student_id'],
                'school_year_id' => (int) $row['school_year_id'],
                'status' => (string) $row['status'],
                'projected_grade' => (int) $row['projected_grade'],
                'student_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'class_name' => (string) $row['class_name'],
                'school_year_label' => (string) $row['school_year_label'],
                'starts_on' => (string) $row['starts_on'],
                'ends_on' => (string) $row['ends_on'],
                'locker_id' => (int) $row['locker_id'],
                'locker_short_name' => (string) $row['locker_short_name'],
                'building_name' => (string) $row['building_name'],
                'floor_name' => (string) $row['floor_name'],
                'area_name' => (string) $row['area_name'],
                'change_limit' => $limit,
                'changes_used' => $used,
                'changes_remaining' => $parent->adminPreview ? null : max(0, $limit - $used),
                'change_limit_applies' => !$parent->adminPreview,
            ];
        }

        return $result;
    }

    /** @return list<array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int}> */
    public function lockerOptions(AuthenticatedParent $parent, int $bookingId): array
    {
        $booking = $this->bookingForParent($parent->id, $bookingId, false, false);
        $candidates = $this->availableLockerCandidates(
            $booking['school_year_id'],
            $booking['projected_grade'],
            $booking['locker_id'],
        );

        return array_map(
            static fn (array $candidate): array => [
                'id' => $candidate['id'],
                'short_name' => $candidate['short_name'],
                'building_name' => $candidate['building_name'],
                'floor_name' => $candidate['floor_name'],
                'area_name' => $candidate['area_name'],
                'score' => $candidate['score'],
            ],
            $candidates,
        );
    }

    /** @return list<array<string, mixed>> */
    public function renewalPlans(AuthenticatedParent $parent, int $bookingId): array
    {
        $source = $this->bookingForParent($parent->id, $bookingId, true, false);
        if ($source['status'] !== BookingStatus::Active->value) {
            return [];
        }

        $years = $this->pdo->prepare(
            'SELECT sy.id, sy.label, sy.starts_on, sy.ends_on, sy.annual_fee_cents '
            . 'FROM school_years sy '
            . 'WHERE sy.starts_on > :starts_on '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM booking_slots bs WHERE bs.school_year_id = sy.id AND bs.student_id = :student_id'
            . ') ORDER BY sy.starts_on ASC LIMIT 4'
        );
        $years->execute([
            'starts_on' => $source['school_year_starts_on'],
            'student_id' => $source['student_id'],
        ]);

        $result = [];
        foreach ($years->fetchAll(PDO::FETCH_ASSOC) as $year) {
            if (!is_array($year)) {
                continue;
            }
            $yearDelta = $this->yearDelta($source['school_year_starts_on'], (string) $year['starts_on']);
            $projectedGrade = $source['projected_grade'] + $yearDelta;
            if ($yearDelta < 1 || $projectedGrade > 12) {
                continue;
            }

            $suggestion = $this->renewalLockerPreview(
                (int) $year['id'],
                $projectedGrade,
                $source['locker_id'],
                $this->transitionRequiresLockerChange($source['projected_grade'], $projectedGrade),
            );
            if ($suggestion === null) {
                continue;
            }

            $result[] = [
                'school_year_id' => (int) $year['id'],
                'label' => (string) $year['label'],
                'starts_on' => (string) $year['starts_on'],
                'ends_on' => (string) $year['ends_on'],
                'annual_fee_cents' => (int) $year['annual_fee_cents'],
                'projected_grade' => $projectedGrade,
                'locker_id' => $suggestion['id'],
                'locker_short_name' => $suggestion['short_name'],
                'reuse_current' => $suggestion['id'] === $source['locker_id'],
                'requires_change' => $suggestion['id'] !== $source['locker_id'],
                'change_reason' => $suggestion['change_reason'],
            ];
        }

        return $result;
    }

    public function changeLocker(
        AuthenticatedParent $parent,
        int $bookingId,
        int $newLockerId,
        int $changeLimit,
    ): void {
        if ($bookingId < 1 || $newLockerId < 1) {
            throw new DomainException('Buchung oder Schließfach ist ungültig.');
        }
        $limit = max(0, min(20, $changeLimit));

        $this->pdo->beginTransaction();
        try {
            $booking = $this->bookingForParent($parent->id, $bookingId, false, true);
            $this->assertNoInFlightPayment($bookingId);
            if (!$parent->adminPreview && $this->parentChangeCount($bookingId) >= $limit) {
                throw new DomainException(
                    $limit === 0
                        ? 'Der Schließfachwechsel im Elternportal ist derzeit deaktiviert.'
                        : 'Das Wechsel-Limit für dieses Schuljahr ist bereits erreicht.',
                );
            }
            if ($booking['locker_id'] === $newLockerId) {
                throw new DomainException('Das ausgewählte Schließfach ist bereits zugeordnet.');
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
            [$actorType, $actorId] = $this->actor($parent);
            $this->insertAssignment(
                $bookingId,
                $booking['school_year_id'],
                $newLockerId,
                $newLocker,
                'parent_locker_change',
                $actorType,
                $actorId,
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
                $booking['locker_id'],
                $newLockerId,
                null,
                'Wechsel durch das Elternportal',
                $actorType,
                $actorId,
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
        AuthenticatedParent $parent,
        int $bookingId,
        int $targetSchoolYearId,
        bool $requestBut,
    ): int {
        if ($bookingId < 1 || $targetSchoolYearId < 1) {
            throw new DomainException('Buchung oder Zielschuljahr ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $source = $this->bookingForParent($parent->id, $bookingId, true, true);
            $this->assertNoInFlightPayment($bookingId);
            $targetYear = $this->targetSchoolYear($targetSchoolYearId);
            if ($targetYear['starts_on'] <= $source['school_year_starts_on']) {
                throw new DomainException('Eine Verlängerung ist nur in ein späteres Schuljahr möglich.');
            }

            $yearDelta = $this->yearDelta($source['school_year_starts_on'], $targetYear['starts_on']);
            $projectedGrade = $source['projected_grade'] + $yearDelta;
            if ($yearDelta < 1 || $projectedGrade > 12) {
                throw new DomainException('Für das gewählte Zielschuljahr ist keine reguläre Klassenstufe mehr ableitbar.');
            }
            $this->assertStudentYearAvailable($source['student_id'], $targetSchoolYearId);

            $forceChange = $this->transitionRequiresLockerChange($source['projected_grade'], $projectedGrade);
            $suggestion = $this->selectRenewalLocker(
                $targetSchoolYearId,
                $projectedGrade,
                $source['locker_id'],
                $forceChange,
            );
            if ($suggestion === null) {
                throw new DomainException(
                    $forceChange
                        ? 'Für den Wechsel in Klassenstufe 7 ist derzeit kein freies regelkonformes anderes Schließfach verfügbar.'
                        : 'Für das Zielschuljahr ist derzeit kein freies regelkonformes Schließfach verfügbar.',
                );
            }
            $lockerId = $suggestion['id'];
            $locker = $suggestion['locker'];
            $decision = $suggestion['decision'];

            $studentSnapshot = $this->studentSnapshot($source['student_id']);
            $annualFee = $targetYear['annual_fee_cents'];
            $status = $annualFee === 0
                ? BookingStatus::Active
                : ($requestBut ? BookingStatus::ExemptionReview : BookingStatus::PaymentDue);
            $feeExemption = $requestBut && $annualFee > 0 ? 'but_pending' : null;
            $chargedFee = $status === BookingStatus::PaymentDue ? $annualFee : 0;
            $paymentDays = max(1, min(365, $this->renewalPaymentDays));
            $paymentDueExpression = $status === BookingStatus::PaymentDue
                ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $paymentDays . ' DAY)'
                : 'NULL';

            $statement = $this->pdo->prepare(
                'INSERT INTO bookings '
                . '(student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, '
                . 'initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, '
                . 'previous_booking_id, student_snapshot, rule_snapshot, created_at, updated_at, payment_due_at) '
                . "VALUES (:student_id, :school_year_id, :status, :projected_grade, :valid_from, :valid_until, 'parent', "
                . ':initiated_by_id, :annual_fee_cents, :charged_fee_cents, 12, :fee_exemption_type, '
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
                'initiated_by_id' => $parent->id,
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
                'locker_id' => $lockerId,
                'booking_id' => $newBookingId,
            ]);
            [$actorType, $actorId] = $this->actor($parent);
            $this->insertAssignment(
                $newBookingId,
                $targetSchoolYearId,
                $lockerId,
                $locker,
                'renewal',
                $actorType,
                $actorId,
            );
            $this->lockCabinetGroup($locker['cabinet_group_id']);
            $reason = $lockerId === $source['locker_id']
                ? 'Verlängerung in das Schuljahr ' . $targetYear['label'] . '; bisheriges Schließfach übernommen'
                : 'Verlängerung in das Schuljahr ' . $targetYear['label'] . '; Schließfachwechsel erforderlich';
            $this->insertEvent(
                $bookingId,
                'renewed',
                $newBookingId,
                $source['locker_id'],
                $lockerId,
                $targetYear['starts_on'],
                $reason,
                $actorType,
                $actorId,
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

    private function parentChangeCount(int $bookingId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM booking_lifecycle_events WHERE booking_id = :booking_id "
            . "AND event_type = 'locker_changed' AND actor_type = 'parent'"
        );
        $statement->execute(['booking_id' => $bookingId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{student_id:int,school_year_id:int,status:string,projected_grade:int,locker_id:int,school_year_starts_on:string}
     */
    private function bookingForParent(int $parentId, int $bookingId, bool $activeOnly, bool $forUpdate): array
    {
        $statusSql = $activeOnly
            ? "AND b.status = 'active' "
            : "AND b.status IN ('active','exemption_review','payment_due') ";
        $locking = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT b.student_id, b.school_year_id, b.status, b.projected_grade, lo.locker_id, '
            . 'sy.starts_on AS school_year_starts_on '
            . 'FROM bookings b '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'WHERE b.id = :booking_id AND psl.parent_contact_id = :parent_contact_id '
            . $statusSql . 'LIMIT 1' . $locking
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'parent_contact_id' => $parentId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Diese aktive Buchung ist Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'student_id' => (int) $row['student_id'],
            'school_year_id' => (int) $row['school_year_id'],
            'status' => (string) $row['status'],
            'projected_grade' => (int) $row['projected_grade'],
            'locker_id' => (int) $row['locker_id'],
            'school_year_starts_on' => (string) $row['school_year_starts_on'],
        ];
    }

    /** @return array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int,change_reason:string}|null */
    private function renewalLockerPreview(
        int $schoolYearId,
        int $projectedGrade,
        int $currentLockerId,
        bool $forceChange,
    ): ?array {
        if (!$forceChange && $this->lockerAvailable($schoolYearId, $currentLockerId)) {
            try {
                $locker = $this->lockerDetails($currentLockerId);
                $decision = $this->allocationRules->evaluate($schoolYearId, $projectedGrade, $currentLockerId);
                if ($decision->allowed && $this->lockerAvailable($schoolYearId, $currentLockerId)) {
                    return [
                        'id' => $currentLockerId,
                        'short_name' => (string) $locker['short_name'],
                        'building_name' => (string) $locker['building_name'],
                        'floor_name' => (string) $locker['floor_name'],
                        'area_name' => (string) $locker['area_name'],
                        'score' => $decision->score,
                        'change_reason' => '',
                    ];
                }
            } catch (DomainException) {
                // Try an alternative locker below.
            }
        }

        $candidates = $this->availableLockerCandidates($schoolYearId, $projectedGrade, $currentLockerId);
        if ($candidates === []) {
            return null;
        }
        $candidate = $candidates[0];
        $candidate['change_reason'] = $forceChange
            ? 'Beim Übergang von Klassenstufe 6 zu 7 ist ein anderes Schließfach erforderlich.'
            : 'Das bisherige Schließfach kann im Zielschuljahr nicht übernommen werden.';

        return $candidate;
    }

    /** @return array{id:int,short_name:string,change_reason:string,locker:array<string,mixed>,decision:AllocationDecision}|null */
    private function selectRenewalLocker(
        int $schoolYearId,
        int $projectedGrade,
        int $currentLockerId,
        bool $forceChange,
    ): ?array {
        if (!$forceChange) {
            try {
                $locker = $this->bookableLocker($currentLockerId);
                if ($this->lockerAvailable($schoolYearId, $currentLockerId)) {
                    $decision = $this->allocationRules->evaluate($schoolYearId, $projectedGrade, $currentLockerId);
                    if ($decision->allowed && $this->lockerAvailable($schoolYearId, $currentLockerId)) {
                        return [
                            'id' => $currentLockerId,
                            'short_name' => (string) $locker['short_name'],
                            'change_reason' => '',
                            'locker' => $locker,
                            'decision' => $decision,
                        ];
                    }
                }
            } catch (DomainException) {
                // Try an alternative locker below.
            }
        }

        foreach ($this->availableLockerCandidates($schoolYearId, $projectedGrade, $currentLockerId) as $candidate) {
            try {
                $locker = $this->bookableLocker($candidate['id']);
                if (!$this->lockerAvailable($schoolYearId, $candidate['id'])) {
                    continue;
                }
                $decision = $this->allocationRules->evaluate($schoolYearId, $projectedGrade, $candidate['id']);
                if (!$decision->allowed) {
                    continue;
                }

                return [
                    'id' => $candidate['id'],
                    'short_name' => $candidate['short_name'],
                    'change_reason' => $forceChange
                        ? 'Beim Übergang von Klassenstufe 6 zu 7 ist ein anderes Schließfach erforderlich.'
                        : 'Das bisherige Schließfach kann im Zielschuljahr nicht übernommen werden.',
                    'locker' => $locker,
                    'decision' => $decision,
                ];
            } catch (DomainException) {
                continue;
            }
        }

        return null;
    }

    /** @return list<array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int}> */
    private function availableLockerCandidates(int $schoolYearId, int $projectedGrade, int $excludeLockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.short_name, bu.id AS building_id, bu.name AS building_name, f.id AS floor_id, '
            . 'f.name AS floor_name, a.id AS area_id, a.name AS area_name, cg.id AS cabinet_group_id '
            . 'FROM lockers l '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.school_year_id = :school_year_id AND lo.locker_id = l.id '
            . 'WHERE l.id <> :exclude_locker_id AND lo.locker_id IS NULL '
            . 'AND l.active = 1 AND l.bookable = 1 AND l.operating_status = \'operational\' '
            . 'AND c.active = 1 AND cg.active = 1 AND a.active = 1 AND f.active = 1 AND bu.active = 1 '
            . 'ORDER BY l.id LIMIT 500'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'exclude_locker_id' => $excludeLockerId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $locations = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->lockerAvailable($schoolYearId, (int) $row['id'])) {
                continue;
            }
            $locations[] = [
                'locker_id' => (int) $row['id'],
                'building_id' => (int) $row['building_id'],
                'floor_id' => (int) $row['floor_id'],
                'area_id' => (int) $row['area_id'],
                'cabinet_group_id' => (int) $row['cabinet_group_id'],
            ];
        }
        $decisions = $this->allocationRules->evaluateLocations($schoolYearId, $projectedGrade, $locations);

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lockerId = (int) $row['id'];
            $decision = $decisions[$lockerId] ?? null;
            if (!$decision instanceof AllocationDecision || !$decision->allowed || !$this->lockerAvailable($schoolYearId, $lockerId)) {
                continue;
            }
            $result[] = [
                'id' => $lockerId,
                'short_name' => (string) $row['short_name'],
                'building_name' => (string) $row['building_name'],
                'floor_name' => (string) $row['floor_name'],
                'area_name' => (string) $row['area_name'],
                'score' => $decision->score,
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: strcmp($left['short_name'], $right['short_name']),
        );

        return $result;
    }

    /** @phpstan-impure */
    private function lockerAvailable(int $schoolYearId, int $lockerId): bool
    {
        $occupied = $this->pdo->prepare(
            'SELECT 1 FROM locker_occupancies WHERE school_year_id = :school_year_id AND locker_id = :locker_id LIMIT 1'
        );
        $occupied->execute(['school_year_id' => $schoolYearId, 'locker_id' => $lockerId]);
        if ($occupied->fetchColumn() !== false) {
            return false;
        }

        $reserved = $this->pdo->prepare(
            'SELECT 1 FROM reservation_slots rs '
            . 'INNER JOIN locker_reservations lr ON lr.id = rs.reservation_id '
            . 'WHERE rs.school_year_id = :school_year_id AND rs.locker_id = :locker_id '
            . "AND ((lr.status = 'active' AND lr.expires_at > CURRENT_TIMESTAMP) "
            . "OR (lr.status = 'payment_running' AND lr.payment_grace_expires_at > CURRENT_TIMESTAMP)) LIMIT 1"
        );
        $reserved->execute(['school_year_id' => $schoolYearId, 'locker_id' => $lockerId]);

        return $reserved->fetchColumn() === false;
    }

    private function assertLockerAvailable(int $schoolYearId, int $lockerId): void
    {
        if (!$this->lockerAvailable($schoolYearId, $lockerId)) {
            throw new DomainException('Das Schließfach ist im gewählten Schuljahr bereits belegt oder reserviert.');
        }
    }

    /** @return array<string, mixed> */
    private function lockerDetails(int $lockerId): array
    {
        return $this->lockerRow($lockerId, false);
    }

    /** @return array<string, mixed> */
    private function bookableLocker(int $lockerId): array
    {
        return $this->lockerRow($lockerId, true);
    }

    /** @return array<string, mixed> */
    private function lockerRow(int $lockerId, bool $forUpdate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.short_name, l.position_no AS locker_position, c.position_no AS corpus_position, '
            . 'cg.id AS cabinet_group_id, cg.code AS group_code, a.code AS area_code, a.name AS area_name, '
            . 'f.code AS floor_code, f.name AS floor_name, bu.code AS building_code, bu.name AS building_name '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'WHERE l.id = :id AND l.active = 1 AND l.bookable = 1 '
            . "AND l.operating_status = 'operational' AND c.active = 1 AND cg.active = 1 "
            . 'AND a.active = 1 AND f.active = 1 AND bu.active = 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
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

    /** @param array<string, mixed> $locker */
    private function insertAssignment(
        int $bookingId,
        int $schoolYearId,
        int $lockerId,
        array $locker,
        string $reason,
        string $actorType,
        ?int $actorId,
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
            . 'VALUES (:booking_id, :school_year_id, :locker_id, CURRENT_TIMESTAMP, :reason, :actor_type, :actor_id, '
            . ':locker_snapshot, CURRENT_TIMESTAMP)'
        )->execute([
            'booking_id' => $bookingId,
            'school_year_id' => $schoolYearId,
            'locker_id' => $lockerId,
            'reason' => $reason,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
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

    private function insertEvent(
        int $bookingId,
        string $eventType,
        ?int $relatedBookingId,
        ?int $oldLockerId,
        ?int $newLockerId,
        ?string $effectiveOn,
        string $reason,
        string $actorType,
        ?int $actorId,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO booking_lifecycle_events '
            . '(booking_id, event_type, related_booking_id, old_locker_id, new_locker_id, effective_on, reason, '
            . 'actor_type, actor_id, created_at) VALUES (:booking_id, :event_type, :related_booking_id, '
            . ':old_locker_id, :new_locker_id, :effective_on, :reason, :actor_type, :actor_id, CURRENT_TIMESTAMP)'
        )->execute([
            'booking_id' => $bookingId,
            'event_type' => $eventType,
            'related_booking_id' => $relatedBookingId,
            'old_locker_id' => $oldLockerId,
            'new_locker_id' => $newLockerId,
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    /** @return array{0:string,1:int|null} */
    private function actor(AuthenticatedParent $parent): array
    {
        if ($parent->adminPreview && $parent->previewStaffUserId !== null) {
            return ['staff', $parent->previewStaffUserId];
        }

        return ['parent', $parent->id];
    }

    private function transitionRequiresLockerChange(int $sourceGrade, int $targetGrade): bool
    {
        return $sourceGrade <= 6 && $targetGrade >= 7;
    }

    private function yearDelta(string $sourceStartsOn, string $targetStartsOn): int
    {
        return (int) substr($targetStartsOn, 0, 4) - (int) substr($sourceStartsOn, 0, 4);
    }
}
