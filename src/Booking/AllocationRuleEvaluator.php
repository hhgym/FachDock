<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use PDO;

final class AllocationRuleEvaluator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function evaluate(int $schoolYearId, int $projectedGrade, int $lockerId): AllocationDecision
    {
        $this->assertGrade($projectedGrade);

        return $this->decision(
            $projectedGrade,
            $lockerId,
            $this->lockerLocation($lockerId),
            $this->applicableRules($schoolYearId, $projectedGrade),
        );
    }

    /**
     * @param list<array{locker_id: int, building_id: int, floor_id: int, area_id: int, cabinet_group_id: int}> $locations
     * @return array<int, AllocationDecision>
     */
    public function evaluateLocations(int $schoolYearId, int $projectedGrade, array $locations): array
    {
        $this->assertGrade($projectedGrade);
        $rules = $this->applicableRules($schoolYearId, $projectedGrade);
        $decisions = [];

        foreach ($locations as $location) {
            $lockerId = $location['locker_id'];
            $decisions[$lockerId] = $this->decision(
                $projectedGrade,
                $lockerId,
                [
                    'building_id' => $location['building_id'],
                    'floor_id' => $location['floor_id'],
                    'area_id' => $location['area_id'],
                    'cabinet_group_id' => $location['cabinet_group_id'],
                ],
                $rules,
            );
        }

        return $decisions;
    }

    /**
     * @param array{building_id: int, floor_id: int, area_id: int, cabinet_group_id: int} $location
     * @param list<array<string, mixed>> $rules
     */
    private function decision(
        int $projectedGrade,
        int $lockerId,
        array $location,
        array $rules,
    ): AllocationDecision {
        $allowRules = [];
        $matchedAllows = [];
        $matchedDenies = [];
        $matchedSoft = [];
        $score = 0;

        foreach ($rules as $rule) {
            $kind = AllocationRuleKind::tryFrom((string) $rule['rule_kind']);
            if ($kind === null) {
                throw new DomainException('Eine aktive Zuteilungsregel hat einen unbekannten Typ.');
            }

            $matches = $this->matchesScope($rule, $location);
            if ($kind === AllocationRuleKind::HardAllow) {
                $allowRules[] = $this->snapshotRule($rule, $matches);
                if ($matches) {
                    $matchedAllows[] = (int) $rule['id'];
                }
                continue;
            }
            if ($kind === AllocationRuleKind::HardDeny && $matches) {
                $matchedDenies[] = (int) $rule['id'];
                continue;
            }
            if ($kind === AllocationRuleKind::SoftPrefer && $matches) {
                $score += (int) $rule['weight'];
                $matchedSoft[] = (int) $rule['id'];
            }
        }

        $allowed = $matchedDenies === [] && ($allowRules === [] || $matchedAllows !== []);
        $reason = null;
        if ($matchedDenies !== []) {
            $reason = 'Das Schließfach ist durch eine verbindliche Zuteilungsregel ausgeschlossen.';
        } elseif ($allowRules !== [] && $matchedAllows === []) {
            $reason = 'Das Schließfach liegt außerhalb der für diese Klassenstufe freigegebenen Bereiche.';
        }

        return new AllocationDecision(
            $allowed,
            $score,
            [
                'projected_grade' => $projectedGrade,
                'locker_id' => $lockerId,
                'hard_allow_rules' => $allowRules,
                'matched_hard_allow_ids' => $matchedAllows,
                'matched_hard_deny_ids' => $matchedDenies,
                'matched_soft_rule_ids' => $matchedSoft,
                'score' => $score,
                'allowed' => $allowed,
            ],
            $reason,
        );
    }

    /** @return array{building_id: int, floor_id: int, area_id: int, cabinet_group_id: int} */
    private function lockerLocation(int $lockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS building_id, f.id AS floor_id, a.id AS area_id, cg.id AS cabinet_group_id '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id WHERE l.id = :locker_id'
        );
        $statement->execute(['locker_id' => $lockerId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Das Schließfach existiert nicht.');
        }

        return [
            'building_id' => (int) $row['building_id'],
            'floor_id' => (int) $row['floor_id'],
            'area_id' => (int) $row['area_id'],
            'cabinet_group_id' => (int) $row['cabinet_group_id'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function applicableRules(int $schoolYearId, int $projectedGrade): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ar.id, ar.name, ar.version, ar.rule_kind, ar.min_grade, ar.max_grade, ar.building_id, '
            . 'ar.floor_id, ar.area_id, ar.cabinet_group_id, ar.weight, ar.priority, ar.updated_at '
            . 'FROM allocation_rules ar '
            . 'INNER JOIN school_years target_year ON target_year.id = :school_year_id '
            . 'LEFT JOIN school_years valid_from ON valid_from.id = ar.valid_from_school_year_id '
            . 'LEFT JOIN school_years valid_until ON valid_until.id = ar.valid_until_school_year_id '
            . 'LEFT JOIN school_year_rule_overrides override_rule '
            . 'ON override_rule.school_year_id = target_year.id AND override_rule.allocation_rule_id = ar.id '
            . 'WHERE ar.active = 1 AND :projected_grade BETWEEN ar.min_grade AND ar.max_grade '
            . 'AND COALESCE(override_rule.enabled, 1) = 1 '
            . 'AND (valid_from.starts_on IS NULL OR valid_from.starts_on <= target_year.starts_on) '
            . 'AND (valid_until.starts_on IS NULL OR valid_until.starts_on >= target_year.starts_on) '
            . 'ORDER BY ar.priority ASC, ar.id ASC'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'projected_grade' => $projectedGrade,
        ]);

        return array_values($statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $rule
     * @param array{building_id: int, floor_id: int, area_id: int, cabinet_group_id: int} $location
     */
    private function matchesScope(array $rule, array $location): bool
    {
        foreach (['building_id', 'floor_id', 'area_id', 'cabinet_group_id'] as $field) {
            if ($rule[$field] !== null && (int) $rule[$field] !== $location[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function snapshotRule(array $rule, bool $matches): array
    {
        return [
            'id' => (int) $rule['id'],
            'version' => (int) $rule['version'],
            'name' => (string) $rule['name'],
            'kind' => (string) $rule['rule_kind'],
            'priority' => (int) $rule['priority'],
            'weight' => (int) $rule['weight'],
            'updated_at' => (string) $rule['updated_at'],
            'matches' => $matches,
        ];
    }

    private function assertGrade(int $projectedGrade): void
    {
        if ($projectedGrade < 5 || $projectedGrade > 12) {
            throw new DomainException('Die prognostizierte Klassenstufe muss zwischen 5 und 12 liegen.');
        }
    }
}
