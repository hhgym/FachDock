<?php

declare(strict_types=1);

namespace FachDock\Location;

use DomainException;
use PDO;

final class LocationCatalogService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createBuilding(string $code, string $name): int
    {
        $this->assertText($code, 'Gebäudekürzel');
        $this->assertText($name, 'Gebäudename');
        $statement = $this->pdo->prepare(
            'INSERT INTO buildings (code, name, active, created_at, updated_at) '
            . 'VALUES (:code, :name, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute(['code' => trim($code), 'name' => trim($name)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createFloor(int $buildingId, string $code, string $name, int $sortOrder = 0): int
    {
        $this->assertText($code, 'Etagenkürzel');
        $this->assertText($name, 'Etagenname');
        $statement = $this->pdo->prepare(
            'INSERT INTO floors (building_id, code, name, sort_order, active, created_at, updated_at) '
            . 'VALUES (:building_id, :code, :name, :sort_order, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'building_id' => $buildingId,
            'code' => trim($code),
            'name' => trim($name),
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createArea(int $floorId, string $code, string $name): int
    {
        $this->assertText($code, 'Bereichskürzel');
        $this->assertText($name, 'Bereichsname');
        $statement = $this->pdo->prepare(
            'INSERT INTO areas (floor_id, code, name, active, created_at, updated_at) '
            . 'VALUES (:floor_id, :code, :name, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute(['floor_id' => $floorId, 'code' => trim($code), 'name' => trim($name)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function buildings(): array
    {
        return $this->rows('SELECT id, code, name, active FROM buildings ORDER BY name, code');
    }

    /** @return list<array<string, mixed>> */
    public function floors(): array
    {
        return $this->rows(
            'SELECT f.id, f.building_id, f.code, f.name, f.sort_order, f.active, b.name AS building_name '
            . 'FROM floors f INNER JOIN buildings b ON b.id = f.building_id '
            . 'ORDER BY b.name, f.sort_order, f.name'
        );
    }

    /** @return list<array<string, mixed>> */
    public function areas(): array
    {
        return $this->rows(
            'SELECT a.id, a.floor_id, a.code, a.name, a.active, f.code AS floor_code, '
            . 'f.name AS floor_name, b.name AS building_name '
            . 'FROM areas a INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'ORDER BY b.name, f.sort_order, a.code, a.name'
        );
    }

    /** @return list<array<string, mixed>> */
    public function corpusTypes(): array
    {
        return $this->rows(
            'SELECT ct.id, ct.code, ct.name, ct.compartment_count, ct.active, '
            . 'GROUP_CONCAT(CASE WHEN p.barrier_friendly = 1 THEN p.position_no END '
            . 'ORDER BY p.position_no SEPARATOR ",") AS barrier_positions '
            . 'FROM corpus_types ct LEFT JOIN corpus_type_positions p ON p.corpus_type_id = ct.id '
            . 'GROUP BY ct.id, ct.code, ct.name, ct.compartment_count, ct.active '
            . 'ORDER BY ct.name, ct.code'
        );
    }

    /** @return list<array<string, mixed>> */
    public function cabinetGroups(): array
    {
        return $this->rows(
            'SELECT cg.id, cg.code, cg.name, cg.active, cg.structure_locked_at, a.code AS area_code, '
            . 'a.name AS area_name, f.code AS floor_code, b.name AS building_name, '
            . 'COUNT(DISTINCT c.id) AS corpus_count, COUNT(l.id) AS locker_count '
            . 'FROM cabinet_groups cg INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id INNER JOIN buildings b ON b.id = f.building_id '
            . 'LEFT JOIN corpuses c ON c.cabinet_group_id = cg.id LEFT JOIN lockers l ON l.corpus_id = c.id '
            . 'GROUP BY cg.id, cg.code, cg.name, cg.active, cg.structure_locked_at, a.code, a.name, f.code, b.name '
            . 'ORDER BY cg.id'
        );
    }

    /** @return list<array<string, mixed>> */
    public function corpusesForGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.position_no, c.corpus_type_id, ct.code AS type_code, ct.name AS type_name, '
            . 'ct.compartment_count FROM corpuses c INNER JOIN corpus_types ct ON ct.id = c.corpus_type_id '
            . 'WHERE c.cabinet_group_id = :group_id ORDER BY c.position_no'
        );
        $statement->execute(['group_id' => $groupId]);
        $rows = $statement->fetchAll();

        return array_values($rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $rows = $this->pdo->query($sql)->fetchAll();

        return array_values($rows);
    }

    private function assertText(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new DomainException($label . ' darf nicht leer sein.');
        }
    }
}
