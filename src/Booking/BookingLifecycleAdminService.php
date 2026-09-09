<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use PDO;

final class BookingLifecycleAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $allocationRules,
    ) {
    }

    /**
     * @return list<array{
     *     id:int,
     *     label:string,
     *     starts_on:string,
     *     ends_on:string,
     *     annual_fee_cents:int,
     *     projected_grade:int,
     *     locker_id:int,
     *     locker_short_name:string,
     *     reuse_current:bool,
     *     requires_change:bool,
     *     change_reason:string
     * }>
     */
    public function renewalTargets(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.student_id, b.projected_grade, b.status, sy.starts_on, lo.locker_id '
            . 'FROM bookings b INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id WHERE b.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($booking) || (string) $booking['status'] !== BookingStatus::Active->value || $booking['locker_id'] === null) {
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
            'starts_on' => (string) $booking['starts_on'],
            'student_id' => (int) $booking['student_id'],
        ]);

        $selector = new RenewalLockerSelector($this->pdo, $this->allocationRules);
        $result = [];
        foreach ($years->fetchAll(PDO::FETCH_ASSOC) as $year) {
            if (!is_array($year)) {
                continue;
            }
            $delta = (int) substr((string) $year['starts_on'], 0, 4)
                - (int) substr((string) $booking['starts_on'], 0, 4);
            $projectedGrade = (int) $booking['projected_grade'] + $delta;
            if ($delta < 1 || $projectedGrade > 12) {
                continue;
            }

            $forceChange = (int) $booking['projected_grade'] <= 6 && $projectedGrade >= 7;
            $selection = $selector->preview(
                (int) $year['id'],
                $projectedGrade,
                (int) $booking['locker_id'],
                $forceChange,
            );
            if ($selection === null) {
                continue;
            }

            $result[] = [
                'id' => (int) $year['id'],
                'label' => (string) $year['label'],
                'starts_on' => (string) $year['starts_on'],
                'ends_on' => (string) $year['ends_on'],
                'annual_fee_cents' => (int) $year['annual_fee_cents'],
                'projected_grade' => $projectedGrade,
                'locker_id' => $selection['id'],
                'locker_short_name' => $selection['short_name'],
                'reuse_current' => $selection['reuse_current'],
                'requires_change' => !$selection['reuse_current'],
                'change_reason' => $selection['change_reason'],
            ];
        }

        return $result;
    }

    /** @return list<array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int}> */
    public function lockerOptions(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.school_year_id, b.projected_grade, lo.locker_id AS current_locker_id '
            . 'FROM bookings b INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'WHERE b.id = :id AND b.status IN (\'active\',\'exemption_review\',\'payment_due\') LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($booking)) {
            return [];
        }

        $lockers = $this->pdo->prepare(
            'SELECT l.id, l.short_name, b.id AS building_id, b.name AS building_name, f.id AS floor_id, '
            . 'f.name AS floor_name, a.id AS area_id, a.name AS area_name, cg.id AS cabinet_group_id '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.school_year_id = :school_year_id AND lo.locker_id = l.id '
            . 'WHERE l.id <> :current_locker_id AND lo.locker_id IS NULL '
            . 'AND l.active = 1 AND l.bookable = 1 AND l.operating_status = \'operational\' '
            . 'AND c.active = 1 AND cg.active = 1 AND a.active = 1 AND f.active = 1 AND b.active = 1 '
            . 'ORDER BY b.name, f.name, a.name, l.short_name LIMIT 500'
        );
        $lockers->execute([
            'school_year_id' => (int) $booking['school_year_id'],
            'current_locker_id' => (int) $booking['current_locker_id'],
        ]);
        $rows = $lockers->fetchAll(PDO::FETCH_ASSOC);
        $locations = [];
        foreach ($rows as $row) {
            $locations[] = [
                'locker_id' => (int) $row['id'],
                'building_id' => (int) $row['building_id'],
                'floor_id' => (int) $row['floor_id'],
                'area_id' => (int) $row['area_id'],
                'cabinet_group_id' => (int) $row['cabinet_group_id'],
            ];
        }
        $decisions = $this->allocationRules->evaluateLocations(
            (int) $booking['school_year_id'],
            (int) $booking['projected_grade'],
            $locations,
        );

        $result = [];
        foreach ($rows as $row) {
            $lockerId = (int) $row['id'];
            $decision = $decisions[$lockerId] ?? null;
            if (!$decision instanceof AllocationDecision || !$decision->allowed) {
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

        usort($result, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['short_name'], $b['short_name']));

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $bookingId): array
    {
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }
        $statement = $this->pdo->prepare(
            'SELECT e.*, old_l.short_name AS old_locker_name, new_l.short_name AS new_locker_name, '
            . 'su.display_name AS actor_name '
            . 'FROM booking_lifecycle_events e '
            . 'LEFT JOIN lockers old_l ON old_l.id = e.old_locker_id '
            . 'LEFT JOIN lockers new_l ON new_l.id = e.new_locker_id '
            . "LEFT JOIN staff_users su ON e.actor_type = 'staff' AND su.id = e.actor_id "
            . 'WHERE e.booking_id = :booking_id OR e.related_booking_id = :booking_id '
            . 'ORDER BY e.created_at DESC, e.id DESC'
        );
        $statement->execute(['booking_id' => $bookingId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
