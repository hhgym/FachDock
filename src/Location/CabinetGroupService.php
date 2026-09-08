<?php

declare(strict_types=1);

namespace FachDock\Location;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class CabinetGroupService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<int> $corpusTypeIds */
    public function create(int $areaId, ?string $name, array $corpusTypeIds): int
    {
        if ($areaId < 1) {
            throw new DomainException('Ein gültiger Bereich ist erforderlich.');
        }
        $this->assertStructure($corpusTypeIds);

        $this->pdo->beginTransaction();
        try {
            $this->assertActiveArea($areaId, true);

            $pendingCode = 'PENDING-' . bin2hex(random_bytes(4));
            $insert = $this->pdo->prepare(
                'INSERT INTO cabinet_groups (area_id, code, name, active, created_at, updated_at) '
                . 'VALUES (:area_id, :code, :name, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'area_id' => $areaId,
                'code' => $pendingCode,
                'name' => $this->nullableName($name),
            ]);

            $groupId = (int) $this->pdo->lastInsertId();
            if ($groupId < 1) {
                throw new RuntimeException('Schrankgruppe konnte nicht angelegt werden.');
            }

            $groupCode = CabinetGroupCode::fromSequence($groupId);
            $this->pdo->prepare(
                'UPDATE cabinet_groups SET code = :code, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'code' => $groupCode,
                'id' => $groupId,
            ]);

            $this->createStructure($groupId, $groupCode, $corpusTypeIds);
            $this->pdo->commit();

            return $groupId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Replaces the complete corpus order of an unused cabinet group.
     *
     * @param list<int> $corpusTypeIds
     */
    public function replaceStructure(int $groupId, array $corpusTypeIds): void
    {
        $this->assertStructure($corpusTypeIds);

        $this->pdo->beginTransaction();
        try {
            $group = $this->loadGroupForUpdate($groupId);
            $this->assertStructureUnlocked($group['structure_locked_at']);

            $deleteLockers = $this->pdo->prepare(
                'DELETE l FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
                . 'WHERE c.cabinet_group_id = :group_id'
            );
            $deleteLockers->execute(['group_id' => $groupId]);
            $this->pdo->prepare('DELETE FROM corpuses WHERE cabinet_group_id = :group_id')
                ->execute(['group_id' => $groupId]);

            $this->createStructure($groupId, $group['code'], $corpusTypeIds);
            $this->pdo->prepare(
                'UPDATE cabinet_groups SET updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $groupId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Locks the physical structure permanently as soon as another module creates
     * the first historical reference (reservation, booking, issue, document, etc.).
     */
    public function lockStructureForHistoricalUse(int $groupId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cabinet_groups SET structure_locked_at = COALESCE(structure_locked_at, CURRENT_TIMESTAMP), '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $groupId]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM cabinet_groups WHERE id = :id');
            $exists->execute(['id' => $groupId]);
            if ($exists->fetchColumn() === false) {
                throw new DomainException('Die Schrankgruppe existiert nicht.');
            }
        }
    }

    public function updateMetadata(int $groupId, int $areaId, ?string $name, bool $active): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->loadGroupForUpdate($groupId);
            $this->assertActiveArea($areaId, true);

            $statement = $this->pdo->prepare(
                'UPDATE cabinet_groups SET area_id = :area_id, name = :name, active = :active, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $groupId,
                'area_id' => $areaId,
                'name' => $this->nullableName($name),
                'active' => $active ? 1 : 0,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteUnused(int $groupId): void
    {
        $this->pdo->beginTransaction();
        try {
            $group = $this->loadGroupForUpdate($groupId);
            $this->assertStructureUnlocked($group['structure_locked_at']);

            $deleteLockers = $this->pdo->prepare(
                'DELETE l FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
                . 'WHERE c.cabinet_group_id = :group_id'
            );
            $deleteLockers->execute(['group_id' => $groupId]);
            $this->pdo->prepare('DELETE FROM corpuses WHERE cabinet_group_id = :group_id')
                ->execute(['group_id' => $groupId]);
            $this->pdo->prepare('DELETE FROM cabinet_groups WHERE id = :id')->execute(['id' => $groupId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<int> $corpusTypeIds */
    private function createStructure(int $groupId, string $groupCode, array $corpusTypeIds): void
    {
        $types = $this->loadCorpusTypes($corpusTypeIds);
        $insertCorpus = $this->pdo->prepare(
            'INSERT INTO corpuses '
            . '(cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) '
            . 'VALUES (:group_id, :type_id, :position, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $insertLocker = $this->pdo->prepare(
            'INSERT INTO lockers '
            . '(corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) '
            . "VALUES (:corpus_id, :position, :short_name, :barrier_friendly, 1, 1, 'operational', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        foreach ($corpusTypeIds as $index => $typeId) {
            $corpusPosition = $index + 1;
            $insertCorpus->execute([
                'group_id' => $groupId,
                'type_id' => $typeId,
                'position' => $corpusPosition,
            ]);
            $corpusId = (int) $this->pdo->lastInsertId();
            $type = $types[$typeId];

            for ($lockerPosition = 1; $lockerPosition <= $type['count']; $lockerPosition++) {
                $insertLocker->execute([
                    'corpus_id' => $corpusId,
                    'position' => $lockerPosition,
                    'short_name' => LockerNaming::shortName($groupCode, $corpusPosition, $lockerPosition),
                    'barrier_friendly' => isset($type['barrier_positions'][$lockerPosition]) ? 1 : 0,
                ]);
            }
        }
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, array{count: int, barrier_positions: array<int, true>}>
     */
    private function loadCorpusTypes(array $typeIds): array
    {
        $types = [];
        $typeStatement = $this->pdo->prepare(
            'SELECT id, compartment_count FROM corpus_types WHERE id = :id AND active = 1'
        );
        $positions = $this->pdo->prepare(
            'SELECT position_no FROM corpus_type_positions '
            . 'WHERE corpus_type_id = :id AND barrier_friendly = 1'
        );

        foreach (array_values(array_unique($typeIds)) as $typeId) {
            $typeStatement->execute(['id' => $typeId]);
            $row = $typeStatement->fetch();
            if (!is_array($row)) {
                throw new DomainException('Mindestens ein ausgewählter Korpustyp ist nicht verfügbar.');
            }

            $positions->execute(['id' => $typeId]);
            $barrierPositions = [];
            foreach ($positions->fetchAll(PDO::FETCH_COLUMN) as $position) {
                $barrierPositions[(int) $position] = true;
            }

            $types[$typeId] = [
                'count' => (int) $row['compartment_count'],
                'barrier_positions' => $barrierPositions,
            ];
        }

        return $types;
    }

    /** @return array{code: string, structure_locked_at: string|null} */
    private function loadGroupForUpdate(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT code, structure_locked_at FROM cabinet_groups WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $groupId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Die Schrankgruppe existiert nicht.');
        }

        return [
            'code' => (string) $row['code'],
            'structure_locked_at' => $row['structure_locked_at'] !== null ? (string) $row['structure_locked_at'] : null,
        ];
    }

    private function assertActiveArea(int $areaId, bool $lock): void
    {
        $sql = 'SELECT a.id FROM areas a '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE a.id = :id AND a.active = 1 AND f.active = 1 AND b.active = 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $areaId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Der ausgewählte Bereich ist nicht verfügbar oder übergeordnet deaktiviert.');
        }
    }

    private function assertStructureUnlocked(?string $lockedAt): void
    {
        if ($lockedAt !== null) {
            throw new DomainException(
                'Die Struktur ist dauerhaft gesperrt, weil die Schrankgruppe bereits historisch verwendet wurde.'
            );
        }
    }

    /** @param list<int> $typeIds */
    private function assertStructure(array $typeIds): void
    {
        if ($typeIds === []) {
            throw new DomainException('Eine Schrankgruppe muss mindestens einen Korpus enthalten.');
        }
        foreach ($typeIds as $typeId) {
            if ($typeId < 1) {
                throw new DomainException('Ungültiger Korpustyp in der Schrankgruppe.');
            }
        }
    }

    private function nullableName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
