<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use PDO;

final class RenewalLockerSelector
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $allocationRules,
    ) {
    }

    /**
     * @return array{
     *     id:int,
     *     short_name:string,
     *     building_name:string,
     *     floor_name:string,
     *     area_name:string,
     *     score:int,
     *     reuse_current:bool,
     *     change_reason:string,
     *     locker:array<string,mixed>,
     *     decision:AllocationDecision
     * }|null
     */
    public function preview(
        int $schoolYearId,
        int $projectedGrade,
        int $currentLockerId,
        bool $forceChange,
    ): ?array {
        return $this->select($schoolYearId, $projectedGrade, $currentLockerId, $forceChange, false);
    }

    /**
     * @return array{
     *     id:int,
     *     short_name:string,
     *     building_name:string,
     *     floor_name:string,
     *     area_name:string,
     *     score:int,
     *     reuse_current:bool,
     *     change_reason:string,
     *     locker:array<string,mixed>,
     *     decision:AllocationDecision
     * }|null
     */
    public function selectForUpdate(
        int $schoolYearId,
        int $projectedGrade,
        int $currentLockerId,
        bool $forceChange,
    ): ?array {
        return $this->select($schoolYearId, $projectedGrade, $currentLockerId, $forceChange, true);
    }

    /**
     * @return array{
     *     id:int,
     *     short_name:string,
     *     building_name:string,
     *     floor_name:string,
     *     area_name:string,
     *     score:int,
     *     reuse_current:bool,
     *     change_reason:string,
     *     locker:array<string,mixed>,
     *     decision:AllocationDecision
     * }|null
     */
    private function select(
        int $schoolYearId,
        int $projectedGrade,
        int $currentLockerId,
        bool $forceChange,
        bool $forUpdate,
    ): ?array {
        if (!$forceChange && $this->lockerAvailable($schoolYearId, $currentLockerId)) {
            try {
                $locker = $this->locker($currentLockerId, $forUpdate);
                $decision = $this->allocationRules->evaluate($schoolYearId, $projectedGrade, $currentLockerId);
                if ($decision->allowed && $this->lockerAvailable($schoolYearId, $currentLockerId)) {
                    return $this->selection($currentLockerId, $locker, $decision, true, '');
                }
            } catch (DomainException) {
                // Use a rule-compliant alternative below.
            }
        }

        foreach ($this->candidates($schoolYearId, $projectedGrade, $currentLockerId) as $candidate) {
            $lockerId = $candidate['id'];
            try {
                $locker = $this->locker($lockerId, $forUpdate);
                if (!$this->lockerAvailable($schoolYearId, $lockerId)) {
                    continue;
                }
                $decision = $this->allocationRules->evaluate($schoolYearId, $projectedGrade, $lockerId);
                if (!$decision->allowed) {
                    continue;
                }

                return $this->selection(
                    $lockerId,
                    $locker,
                    $decision,
                    false,
                    $forceChange
                        ? 'Beim Übergang von Klassenstufe 6 zu 7 ist ein anderes Schließfach erforderlich.'
                        : 'Das bisherige Schließfach kann im Zielschuljahr nicht übernommen werden.',
                );
            } catch (DomainException) {
                continue;
            }
        }

        return null;
    }

    /** @return list<array{id:int,score:int}> */
    private function candidates(int $schoolYearId, int $projectedGrade, int $currentLockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.short_name, bu.id AS building_id, f.id AS floor_id, a.id AS area_id, '
            . 'cg.id AS cabinet_group_id '
            . 'FROM lockers l '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.school_year_id = :school_year_id AND lo.locker_id = l.id '
            . 'WHERE l.id <> :current_locker_id AND lo.locker_id IS NULL '
            . 'AND l.active = 1 AND l.bookable = 1 AND l.operating_status = \'operational\' '
            . 'AND c.active = 1 AND cg.active = 1 AND a.active = 1 AND f.active = 1 AND bu.active = 1 '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM reservation_slots rs '
            . 'INNER JOIN locker_reservations lr ON lr.id = rs.reservation_id '
            . 'WHERE rs.school_year_id = :reservation_school_year_id AND rs.locker_id = l.id '
            . "AND ((lr.status = 'active' AND lr.expires_at > CURRENT_TIMESTAMP) "
            . "OR (lr.status = 'payment_running' AND lr.payment_grace_expires_at > CURRENT_TIMESTAMP))"
            . ') ORDER BY l.id LIMIT 500'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'reservation_school_year_id' => $schoolYearId,
            'current_locker_id' => $currentLockerId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $locations = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
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
            if (!$decision instanceof AllocationDecision || !$decision->allowed) {
                continue;
            }
            $result[] = [
                'id' => $lockerId,
                'score' => $decision->score,
                'short_name' => (string) $row['short_name'],
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: strcmp($left['short_name'], $right['short_name']),
        );

        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'score' => $row['score']],
            $result,
        );
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

    /** @return array<string, mixed> */
    private function locker(int $lockerId, bool $forUpdate): array
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

    /**
     * @param array<string, mixed> $locker
     * @return array{
     *     id:int,
     *     short_name:string,
     *     building_name:string,
     *     floor_name:string,
     *     area_name:string,
     *     score:int,
     *     reuse_current:bool,
     *     change_reason:string,
     *     locker:array<string,mixed>,
     *     decision:AllocationDecision
     * }
     */
    private function selection(
        int $lockerId,
        array $locker,
        AllocationDecision $decision,
        bool $reuseCurrent,
        string $changeReason,
    ): array {
        return [
            'id' => $lockerId,
            'short_name' => (string) $locker['short_name'],
            'building_name' => (string) $locker['building_name'],
            'floor_name' => (string) $locker['floor_name'],
            'area_name' => (string) $locker['area_name'],
            'score' => $decision->score,
            'reuse_current' => $reuseCurrent,
            'change_reason' => $changeReason,
            'locker' => $locker,
            'decision' => $decision,
        ];
    }
}
