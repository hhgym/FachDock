<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Location\LockerNaming;
use PDO;
use RuntimeException;

final class AllocationRuleTestService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $evaluator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function test(int $schoolYearId, int $grade): array
    {
        if ($grade < 5 || $grade > 12) {
            throw new DomainException('Die Klassenstufe muss zwischen 5 und 12 liegen.');
        }
        $year = $this->pdo->prepare('SELECT id FROM school_years WHERE id = :id');
        $year->execute(['id' => $schoolYearId]);
        if ($year->fetchColumn() === false) {
            throw new DomainException('Das Schuljahr existiert nicht.');
        }

        $statement = $this->pdo->query(
            'SELECT l.id AS locker_id, l.short_name, l.bookable, l.operating_status, '
            . 'l.position_no AS locker_position, c.position_no AS corpus_position, '
            . 'cg.id AS cabinet_group_id, cg.code AS group_code, a.id AS area_id, a.code AS area_code, '
            . 'f.id AS floor_id, f.code AS floor_code, b.id AS building_id, b.code AS building_code '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE l.active = 1 AND c.active = 1 AND cg.active = 1 AND a.active = 1 '
            . 'AND f.active = 1 AND b.active = 1 ORDER BY cg.id, c.position_no, l.position_no'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schließfächer konnten für den Regeltest nicht geladen werden.');
        }

        $rows = [];
        $locations = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lockerId = (int) $row['locker_id'];
            $locations[] = [
                'locker_id' => $lockerId,
                'building_id' => (int) $row['building_id'],
                'floor_id' => (int) $row['floor_id'],
                'area_id' => (int) $row['area_id'],
                'cabinet_group_id' => (int) $row['cabinet_group_id'],
            ];
            $rows[$lockerId] = $row;
        }

        $decisions = $this->evaluator->evaluateLocations($schoolYearId, $grade, $locations);
        $result = [];
        foreach ($rows as $lockerId => $row) {
            $decision = $decisions[$lockerId] ?? null;
            if ($decision === null) {
                continue;
            }
            $result[] = [
                'locker_id' => $lockerId,
                'short_name' => (string) $row['short_name'],
                'long_name' => LockerNaming::longName(
                    (string) $row['floor_code'],
                    (string) $row['area_code'],
                    (string) $row['group_code'],
                    (int) $row['corpus_position'],
                    (int) $row['locker_position'],
                ),
                'rule_allowed' => $decision->allowed,
                'score' => $decision->score,
                'reason' => $decision->reason,
                'physically_bookable' => (int) $row['bookable'] === 1
                    && (string) $row['operating_status'] === 'operational',
                'operating_status' => (string) $row['operating_status'],
                'matched_allow_ids' => $decision->snapshot['matched_hard_allow_ids'] ?? [],
                'matched_deny_ids' => $decision->snapshot['matched_hard_deny_ids'] ?? [],
                'matched_soft_ids' => $decision->snapshot['matched_soft_rule_ids'] ?? [],
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $allowed = ((int) $right['rule_allowed']) <=> ((int) $left['rule_allowed']);
            if ($allowed !== 0) {
                return $allowed;
            }
            $score = ((int) $right['score']) <=> ((int) $left['score']);
            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) $left['short_name'], (string) $right['short_name']);
        });

        return $result;
    }
}
