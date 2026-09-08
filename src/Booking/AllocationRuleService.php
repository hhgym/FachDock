<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class AllocationRuleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $name,
        AllocationRuleKind $kind,
        int $minGrade,
        int $maxGrade,
        string $scopeType,
        int $scopeId,
        int $weight,
        int $priority,
        ?int $validFromSchoolYearId,
        ?int $validUntilSchoolYearId,
        ?string $notes,
        bool $active,
    ): int {
        $input = $this->validatedInput(
            $name,
            $kind,
            $minGrade,
            $maxGrade,
            $scopeType,
            $scopeId,
            $weight,
            $priority,
            $validFromSchoolYearId,
            $validUntilSchoolYearId,
            $notes,
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO allocation_rules '
            . '(name, version, rule_kind, min_grade, max_grade, building_id, floor_id, area_id, cabinet_group_id, '
            . 'weight, priority, valid_from_school_year_id, valid_until_school_year_id, notes, active, '
            . 'created_at, updated_at) VALUES '
            . '(:name, 1, :rule_kind, :min_grade, :max_grade, :building_id, :floor_id, :area_id, '
            . ':cabinet_group_id, :weight, :priority, :valid_from, :valid_until, :notes, :active, '
            . 'CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute($input + ['active' => $active ? 1 : 0]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Die Zuteilungsregel konnte nicht angelegt werden.');
        }

        return $id;
    }

    public function update(
        int $ruleId,
        string $name,
        AllocationRuleKind $kind,
        int $minGrade,
        int $maxGrade,
        string $scopeType,
        int $scopeId,
        int $weight,
        int $priority,
        ?int $validFromSchoolYearId,
        ?int $validUntilSchoolYearId,
        ?string $notes,
        bool $active,
    ): void {
        if ($ruleId < 1) {
            throw new DomainException('Die Zuteilungsregel ist ungültig.');
        }
        $input = $this->validatedInput(
            $name,
            $kind,
            $minGrade,
            $maxGrade,
            $scopeType,
            $scopeId,
            $weight,
            $priority,
            $validFromSchoolYearId,
            $validUntilSchoolYearId,
            $notes,
        );

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare('SELECT id FROM allocation_rules WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $ruleId]);
            if ($lookup->fetchColumn() === false) {
                throw new DomainException('Die Zuteilungsregel existiert nicht.');
            }

            $statement = $this->pdo->prepare(
                'UPDATE allocation_rules SET name = :name, version = version + 1, rule_kind = :rule_kind, '
                . 'min_grade = :min_grade, max_grade = :max_grade, building_id = :building_id, '
                . 'floor_id = :floor_id, area_id = :area_id, cabinet_group_id = :cabinet_group_id, '
                . 'weight = :weight, priority = :priority, valid_from_school_year_id = :valid_from, '
                . 'valid_until_school_year_id = :valid_until, notes = :notes, active = :active, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute($input + [
                'active' => $active ? 1 : 0,
                'id' => $ruleId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function setSchoolYearEnabled(int $ruleId, int $schoolYearId, bool $enabled): void
    {
        $this->assertExists('allocation_rules', $ruleId, 'Die Zuteilungsregel existiert nicht.');
        $this->assertExists('school_years', $schoolYearId, 'Das Schuljahr existiert nicht.');

        $statement = $this->pdo->prepare(
            'INSERT INTO school_year_rule_overrides (school_year_id, allocation_rule_id, enabled, updated_at) '
            . 'VALUES (:school_year_id, :rule_id, :enabled, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'school_year_id' => $schoolYearId,
            'rule_id' => $ruleId,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ar.id, ar.name, ar.version, ar.rule_kind, ar.min_grade, ar.max_grade, ar.weight, ar.priority, '
            . 'ar.active, ar.notes, ar.building_id, ar.floor_id, ar.area_id, ar.cabinet_group_id, '
            . 'ar.valid_from_school_year_id, ar.valid_until_school_year_id, '
            . 'vf.label AS valid_from_label, vu.label AS valid_until_label, '
            . 'b.name AS building_name, f.code AS floor_code, f.name AS floor_name, '
            . 'a.code AS area_code, a.name AS area_name, cg.code AS group_code, '
            . "GROUP_CONCAT(CASE WHEN o.enabled = 0 THEN oy.label END ORDER BY oy.starts_on SEPARATOR ', ') "
            . 'AS disabled_years '
            . 'FROM allocation_rules ar '
            . 'LEFT JOIN buildings b ON b.id = ar.building_id '
            . 'LEFT JOIN floors f ON f.id = ar.floor_id '
            . 'LEFT JOIN areas a ON a.id = ar.area_id '
            . 'LEFT JOIN cabinet_groups cg ON cg.id = ar.cabinet_group_id '
            . 'LEFT JOIN school_years vf ON vf.id = ar.valid_from_school_year_id '
            . 'LEFT JOIN school_years vu ON vu.id = ar.valid_until_school_year_id '
            . 'LEFT JOIN school_year_rule_overrides o ON o.allocation_rule_id = ar.id '
            . 'LEFT JOIN school_years oy ON oy.id = o.school_year_id '
            . 'GROUP BY ar.id, ar.name, ar.version, ar.rule_kind, ar.min_grade, ar.max_grade, ar.weight, '
            . 'ar.priority, ar.active, ar.notes, ar.building_id, ar.floor_id, ar.area_id, ar.cabinet_group_id, '
            . 'ar.valid_from_school_year_id, ar.valid_until_school_year_id, vf.label, vu.label, b.name, '
            . 'f.code, f.name, a.code, a.name, cg.code '
            . 'ORDER BY ar.priority, ar.id'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Zuteilungsregeln konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['scope_type'] = $this->scopeType($row);
            $row['scope_id'] = $this->scopeId($row);
            $row['scope_label'] = $this->scopeLabel($row);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function validatedInput(
        string $name,
        AllocationRuleKind $kind,
        int $minGrade,
        int $maxGrade,
        string $scopeType,
        int $scopeId,
        int $weight,
        int $priority,
        ?int $validFromSchoolYearId,
        ?int $validUntilSchoolYearId,
        ?string $notes,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Die Regelbezeichnung darf nicht leer sein.');
        }
        if ($minGrade < 5 || $minGrade > 12 || $maxGrade < 5 || $maxGrade > 12 || $minGrade > $maxGrade) {
            throw new DomainException('Der Klassenstufenbereich muss zwischen 5 und 12 liegen.');
        }
        if ($priority < 1 || $priority > 10000) {
            throw new DomainException('Die Priorität muss zwischen 1 und 10000 liegen.');
        }
        if ($weight < -1000 || $weight > 1000) {
            throw new DomainException('Die Gewichtung muss zwischen -1000 und 1000 liegen.');
        }
        if ($kind !== AllocationRuleKind::SoftPrefer) {
            $weight = 0;
        }

        $scope = $this->scope($scopeType, $scopeId);
        $this->assertSchoolYearRange($validFromSchoolYearId, $validUntilSchoolYearId);
        $notes = trim((string) $notes);

        return [
            'name' => $name,
            'rule_kind' => $kind->value,
            'min_grade' => $minGrade,
            'max_grade' => $maxGrade,
            'building_id' => $scope['building_id'],
            'floor_id' => $scope['floor_id'],
            'area_id' => $scope['area_id'],
            'cabinet_group_id' => $scope['cabinet_group_id'],
            'weight' => $weight,
            'priority' => $priority,
            'valid_from' => $validFromSchoolYearId,
            'valid_until' => $validUntilSchoolYearId,
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    /** @return array{building_id: int|null, floor_id: int|null, area_id: int|null, cabinet_group_id: int|null} */
    private function scope(string $scopeType, int $scopeId): array
    {
        $targets = [
            'building' => ['table' => 'buildings', 'column' => 'building_id'],
            'floor' => ['table' => 'floors', 'column' => 'floor_id'],
            'area' => ['table' => 'areas', 'column' => 'area_id'],
            'cabinet_group' => ['table' => 'cabinet_groups', 'column' => 'cabinet_group_id'],
        ];
        $target = $targets[$scopeType] ?? null;
        if ($target === null || $scopeId < 1) {
            throw new DomainException('Für die Regel muss ein gültiger räumlicher Geltungsbereich gewählt werden.');
        }

        $this->assertExists($target['table'], $scopeId, 'Der gewählte räumliche Geltungsbereich existiert nicht.');
        $scope = [
            'building_id' => null,
            'floor_id' => null,
            'area_id' => null,
            'cabinet_group_id' => null,
        ];
        $scope[$target['column']] = $scopeId;

        return $scope;
    }

    private function assertSchoolYearRange(?int $validFromId, ?int $validUntilId): void
    {
        $from = $validFromId === null ? null : $this->schoolYearStart($validFromId);
        $until = $validUntilId === null ? null : $this->schoolYearStart($validUntilId);
        if ($from !== null && $until !== null && $from > $until) {
            throw new DomainException('Das Ende der Regelgültigkeit liegt vor ihrem Beginn.');
        }
    }

    private function schoolYearStart(int $schoolYearId): string
    {
        $statement = $this->pdo->prepare('SELECT starts_on FROM school_years WHERE id = :id');
        $statement->execute(['id' => $schoolYearId]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new DomainException('Ein ausgewähltes Schuljahr existiert nicht.');
        }

        return (string) $value;
    }

    private function assertExists(string $table, int $id, string $message): void
    {
        if (!in_array($table, ['allocation_rules', 'school_years', 'buildings', 'floors', 'areas', 'cabinet_groups'], true)) {
            throw new RuntimeException('Ungültige interne Tabellenreferenz.');
        }

        $statement = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException($message);
        }
    }

    /** @param array<string, mixed> $row */
    private function scopeType(array $row): string
    {
        if ($row['cabinet_group_id'] !== null) {
            return 'cabinet_group';
        }
        if ($row['area_id'] !== null) {
            return 'area';
        }
        if ($row['floor_id'] !== null) {
            return 'floor';
        }

        return 'building';
    }

    /** @param array<string, mixed> $row */
    private function scopeId(array $row): int
    {
        return match ($this->scopeType($row)) {
            'cabinet_group' => (int) $row['cabinet_group_id'],
            'area' => (int) $row['area_id'],
            'floor' => (int) $row['floor_id'],
            default => (int) $row['building_id'],
        };
    }

    /** @param array<string, mixed> $row */
    private function scopeLabel(array $row): string
    {
        return match ($this->scopeType($row)) {
            'cabinet_group' => 'Schrankgruppe ' . (string) $row['group_code'],
            'area' => 'Bereich ' . (string) $row['area_code'] . ' · ' . (string) $row['area_name'],
            'floor' => 'Etage ' . (string) $row['floor_code'] . ' · ' . (string) $row['floor_name'],
            default => 'Gebäude ' . (string) $row['building_name'],
        };
    }
}
