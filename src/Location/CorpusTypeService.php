<?php

declare(strict_types=1);

namespace FachDock\Location;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class CorpusTypeService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $barrierFriendlyPositions
     * @param array{width_mm?: int|null, height_mm?: int|null, depth_mm?: int|null, compartment_width_mm?: int|null, compartment_height_mm?: int|null, compartment_depth_mm?: int|null} $dimensions
     */
    public function create(
        string $code,
        string $name,
        int $compartmentCount,
        array $barrierFriendlyPositions = [],
        array $dimensions = [],
    ): int {
        $this->assertTypeInput($code, $name, $compartmentCount, $barrierFriendlyPositions);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO corpus_types '
                . '(code, name, compartment_count, width_mm, height_mm, depth_mm, compartment_width_mm, '
                . 'compartment_height_mm, compartment_depth_mm, active, created_at, updated_at) '
                . 'VALUES (:code, :name, :count, :width, :height, :depth, :compartment_width, '
                . ':compartment_height, :compartment_depth, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'code' => trim($code),
                'name' => trim($name),
                'count' => $compartmentCount,
                'width' => $this->dimension($dimensions, 'width_mm'),
                'height' => $this->dimension($dimensions, 'height_mm'),
                'depth' => $this->dimension($dimensions, 'depth_mm'),
                'compartment_width' => $this->dimension($dimensions, 'compartment_width_mm'),
                'compartment_height' => $this->dimension($dimensions, 'compartment_height_mm'),
                'compartment_depth' => $this->dimension($dimensions, 'compartment_depth_mm'),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Korpustyp konnte nicht angelegt werden.');
            }

            $this->replacePositions($id, $compartmentCount, $barrierFriendlyPositions);
            $this->pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<int> $barrierFriendlyPositions
     * @param array{width_mm?: int|null, height_mm?: int|null, depth_mm?: int|null, compartment_width_mm?: int|null, compartment_height_mm?: int|null, compartment_depth_mm?: int|null} $dimensions
     */
    public function update(
        int $id,
        string $code,
        string $name,
        int $compartmentCount,
        array $barrierFriendlyPositions = [],
        array $dimensions = [],
        bool $active = true,
    ): void {
        $this->assertTypeInput($code, $name, $compartmentCount, $barrierFriendlyPositions);

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare('SELECT compartment_count FROM corpus_types WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $id]);
            $existingCount = $lookup->fetchColumn();
            if ($existingCount === false) {
                throw new DomainException('Der Korpustyp existiert nicht.');
            }

            $usage = $this->pdo->prepare('SELECT COUNT(*) FROM corpuses WHERE corpus_type_id = :id');
            $usage->execute(['id' => $id]);
            $isUsed = (int) $usage->fetchColumn() > 0;
            if ($isUsed && (int) $existingCount !== $compartmentCount) {
                throw new DomainException(
                    'Die Anzahl der Fächer kann nicht geändert werden, sobald der Korpustyp verwendet wird.'
                );
            }

            $statement = $this->pdo->prepare(
                'UPDATE corpus_types SET code = :code, name = :name, compartment_count = :count, '
                . 'width_mm = :width, height_mm = :height, depth_mm = :depth, '
                . 'compartment_width_mm = :compartment_width, compartment_height_mm = :compartment_height, '
                . 'compartment_depth_mm = :compartment_depth, active = :active, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'code' => trim($code),
                'name' => trim($name),
                'count' => $compartmentCount,
                'width' => $this->dimension($dimensions, 'width_mm'),
                'height' => $this->dimension($dimensions, 'height_mm'),
                'depth' => $this->dimension($dimensions, 'depth_mm'),
                'compartment_width' => $this->dimension($dimensions, 'compartment_width_mm'),
                'compartment_height' => $this->dimension($dimensions, 'compartment_height_mm'),
                'compartment_depth' => $this->dimension($dimensions, 'compartment_depth_mm'),
                'active' => $active ? 1 : 0,
            ]);

            $this->replacePositions($id, $compartmentCount, $barrierFriendlyPositions);
            if ($isUsed) {
                $this->synchronizeExistingLockers($id);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<int> $barrierFriendlyPositions */
    private function replacePositions(int $typeId, int $count, array $barrierFriendlyPositions): void
    {
        $this->pdo->prepare('DELETE FROM corpus_type_positions WHERE corpus_type_id = :id')->execute(['id' => $typeId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO corpus_type_positions (corpus_type_id, position_no, barrier_friendly) '
            . 'VALUES (:type_id, :position, :barrier_friendly)'
        );
        $barrierMap = array_fill_keys($barrierFriendlyPositions, true);

        for ($position = 1; $position <= $count; $position++) {
            $insert->execute([
                'type_id' => $typeId,
                'position' => $position,
                'barrier_friendly' => isset($barrierMap[$position]) ? 1 : 0,
            ]);
        }
    }

    private function synchronizeExistingLockers(int $typeId): void
    {
        $this->pdo->prepare(
            'UPDATE lockers l '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN corpus_type_positions p ON p.corpus_type_id = c.corpus_type_id '
            . 'AND p.position_no = l.position_no '
            . 'SET l.barrier_friendly = p.barrier_friendly, l.updated_at = CURRENT_TIMESTAMP '
            . 'WHERE c.corpus_type_id = :type_id'
        )->execute(['type_id' => $typeId]);
    }

    /** @param list<int> $positions */
    private function assertTypeInput(string $code, string $name, int $count, array $positions): void
    {
        if (trim($code) === '' || trim($name) === '') {
            throw new DomainException('Kürzel und Bezeichnung des Korpustyps dürfen nicht leer sein.');
        }
        if ($count < 1 || $count > 100) {
            throw new DomainException('Die Anzahl der Fächer muss zwischen 1 und 100 liegen.');
        }
        foreach ($positions as $position) {
            if ($position < 1 || $position > $count) {
                throw new DomainException('Eine barrierearme Position liegt außerhalb des Korpustyps.');
            }
        }
    }

    /** @param array<string, int|null> $dimensions */
    private function dimension(array $dimensions, string $key): ?int
    {
        $value = $dimensions[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if ($value < 1) {
            throw new DomainException('Abmessungen müssen positive Millimeterwerte sein.');
        }

        return $value;
    }
}
