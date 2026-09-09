<?php

declare(strict_types=1);

namespace FachDock\FloorPlan;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class FloorPlanService
{
    private const MAX_UPLOAD_BYTES = 10_485_760;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $root,
    ) {
    }

    /** @return list<array{id:int,name:string,code:string,building_name:string,building_code:string,plan_count:int}> */
    public function floors(): array
    {
        $statement = $this->pdo->query(
            'SELECT f.id, f.name, f.code, b.name AS building_name, b.code AS building_code, '
            . 'COUNT(fp.id) AS plan_count '
            . 'FROM floors f INNER JOIN buildings b ON b.id = f.building_id '
            . 'LEFT JOIN floor_plans fp ON fp.floor_id = f.id AND fp.active = 1 '
            . 'WHERE f.active = 1 AND b.active = 1 '
            . 'GROUP BY f.id, f.name, f.code, b.name, b.code, f.sort_order '
            . 'ORDER BY b.name, f.sort_order, f.name'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Etagen konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'building_name' => (string) $row['building_name'],
                'building_code' => (string) $row['building_code'],
                'plan_count' => (int) $row['plan_count'],
            ];
        }

        return $result;
    }

    /** @return list<array{id:int,label:string,status:string,starts_on:string,ends_on:string}> */
    public function schoolYears(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, label, status, starts_on, ends_on FROM school_years "
            . "WHERE status IN ('current', 'future') OR ends_on >= DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR) "
            . 'ORDER BY starts_on DESC LIMIT 6'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schuljahre konnten nicht geladen werden.');
        }

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'status' => (string) $row['status'],
                'starts_on' => (string) $row['starts_on'],
                'ends_on' => (string) $row['ends_on'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function defaultSchoolYearId(): ?int
    {
        $statement = $this->pdo->query(
            "SELECT id FROM school_years ORDER BY "
            . "CASE status WHEN 'current' THEN 0 WHEN 'future' THEN 1 ELSE 2 END, "
            . 'ABS(DATEDIFF(starts_on, CURRENT_DATE)), starts_on DESC LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return list<array{id:int,floor_id:int,title:string,original_name:string,mime_type:string,sort_order:int}> */
    public function plansForFloor(int $floorId): array
    {
        $this->assertPositive($floorId, 'Etage');
        $statement = $this->pdo->prepare(
            'SELECT id, floor_id, title, original_name, mime_type, sort_order '
            . 'FROM floor_plans WHERE floor_id = :floor_id AND active = 1 ORDER BY sort_order, id'
        );
        $statement->execute(['floor_id' => $floorId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'floor_id' => (int) $row['floor_id'],
                'title' => (string) $row['title'],
                'original_name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array<string, mixed> */
    public function plan(int $planId, ?int $schoolYearId = null): array
    {
        $this->assertPositive($planId, 'Lageplan');
        $schoolYearId ??= $this->defaultSchoolYearId();
        if ($schoolYearId === null) {
            throw new DomainException('Für die Verfügbarkeitsanzeige ist noch kein Schuljahr vorhanden.');
        }

        $statement = $this->pdo->prepare(
            'SELECT fp.id, fp.floor_id, fp.title, fp.original_name, fp.mime_type, fp.sort_order, '
            . 'f.name AS floor_name, f.code AS floor_code, b.name AS building_name, b.code AS building_code '
            . 'FROM floor_plans fp INNER JOIN floors f ON f.id = fp.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE fp.id = :id AND fp.active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $planId]);
        $plan = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plan)) {
            throw new DomainException('Der Lageplan existiert nicht.');
        }

        $groups = $this->groupsForFloor((int) $plan['floor_id'], $planId, $schoolYearId);

        return [
            'id' => (int) $plan['id'],
            'floor_id' => (int) $plan['floor_id'],
            'title' => (string) $plan['title'],
            'original_name' => (string) $plan['original_name'],
            'mime_type' => (string) $plan['mime_type'],
            'sort_order' => (int) $plan['sort_order'],
            'floor_name' => (string) $plan['floor_name'],
            'floor_code' => (string) $plan['floor_code'],
            'building_name' => (string) $plan['building_name'],
            'building_code' => (string) $plan['building_code'],
            'school_year_id' => $schoolYearId,
            'groups' => $groups,
            'image_url' => '/floorplans/image?id=' . (int) $plan['id'],
        ];
    }

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $file
     */
    public function createFromUpload(int $floorId, string $title, array $file, int $staffUserId): int
    {
        $this->assertPositive($floorId, 'Etage');
        $this->assertPositive($staffUserId, 'Benutzer');
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new DomainException('Bitte einen gültigen Titel für den Lageplan angeben.');
        }
        $this->assertFloor($floorId);

        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException('Der Lageplan konnte nicht hochgeladen werden.');
        }
        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new DomainException('Die hochgeladene Datei ist ungültig.');
        }
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new DomainException('Der Lageplan darf höchstens 10 MB groß sein.');
        }

        $mime = $this->detectMime($tmp);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw new DomainException('Lagepläne müssen PNG-, JPEG- oder WebP-Bilder sein.'),
        };
        if (@getimagesize($tmp) === false) {
            throw new DomainException('Die hochgeladene Datei ist kein gültiges Bild.');
        }

        $directory = $this->storageDirectory();
        $storageName = bin2hex(random_bytes(20)) . '.' . $extension;
        $absolute = $directory . '/' . $storageName;
        if (!move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException('Der Lageplan konnte nicht im Speicher abgelegt werden.');
        }

        try {
            return $this->insertPlan(
                $floorId,
                $title,
                'floorplans/' . $storageName,
                $this->safeOriginalName((string) ($file['name'] ?? 'lageplan.' . $extension)),
                $mime,
                $staffUserId,
            );
        } catch (Throwable $exception) {
            @unlink($absolute);
            throw $exception;
        }
    }

    /** Used by integration tests and trusted local imports. */
    public function createFromFile(
        int $floorId,
        string $title,
        string $sourcePath,
        string $originalName,
        string $mime,
        int $staffUserId,
    ): int {
        $this->assertPositive($floorId, 'Etage');
        $this->assertPositive($staffUserId, 'Benutzer');
        $this->assertFloor($floorId);
        $title = trim($title);
        if ($title === '' || !is_file($sourcePath)) {
            throw new DomainException('Der Lageplan ist ungültig.');
        }
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw new DomainException('Lagepläne müssen PNG-, JPEG- oder WebP-Bilder sein.'),
        };
        $directory = $this->storageDirectory();
        $storageName = bin2hex(random_bytes(20)) . '.' . $extension;
        $absolute = $directory . '/' . $storageName;
        if (!copy($sourcePath, $absolute)) {
            throw new RuntimeException('Der Lageplan konnte nicht kopiert werden.');
        }

        try {
            return $this->insertPlan(
                $floorId,
                $title,
                'floorplans/' . $storageName,
                $this->safeOriginalName($originalName),
                $mime,
                $staffUserId,
            );
        } catch (Throwable $exception) {
            @unlink($absolute);
            throw $exception;
        }
    }

    public function setPlacement(
        int $planId,
        int $cabinetGroupId,
        float $xPercent,
        float $yPercent,
        float $widthPercent,
        float $heightPercent,
        int $staffUserId,
    ): void {
        $this->assertPositive($planId, 'Lageplan');
        $this->assertPositive($cabinetGroupId, 'Schrankgruppe');
        $this->assertPositive($staffUserId, 'Benutzer');
        foreach ([$xPercent, $yPercent, $widthPercent, $heightPercent] as $value) {
            if (!is_finite($value)) {
                throw new DomainException('Die Position ist ungültig.');
            }
        }
        if ($xPercent < 0 || $yPercent < 0 || $widthPercent < 2 || $heightPercent < 2
            || $xPercent + $widthPercent > 100 || $yPercent + $heightPercent > 100
        ) {
            throw new DomainException('Die Schrankgruppe muss vollständig innerhalb des Lageplans liegen.');
        }
        if (!$this->groupBelongsToPlanFloor($planId, $cabinetGroupId)) {
            throw new DomainException('Die Schrankgruppe gehört nicht zur Etage dieses Lageplans.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO floor_plan_group_positions '
            . '(floor_plan_id, cabinet_group_id, x_percent, y_percent, width_percent, height_percent, '
            . 'updated_by_staff_user_id, updated_at) '
            . 'VALUES (:plan_id, :group_id, :x, :y, :width, :height, :staff_id, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE x_percent = VALUES(x_percent), y_percent = VALUES(y_percent), '
            . 'width_percent = VALUES(width_percent), height_percent = VALUES(height_percent), '
            . 'updated_by_staff_user_id = VALUES(updated_by_staff_user_id), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'plan_id' => $planId,
            'group_id' => $cabinetGroupId,
            'x' => round($xPercent, 3),
            'y' => round($yPercent, 3),
            'width' => round($widthPercent, 3),
            'height' => round($heightPercent, 3),
            'staff_id' => $staffUserId,
        ]);
    }

    public function removePlacement(int $planId, int $cabinetGroupId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM floor_plan_group_positions WHERE floor_plan_id = :plan_id AND cabinet_group_id = :group_id'
        );
        $statement->execute(['plan_id' => $planId, 'group_id' => $cabinetGroupId]);
    }

    public function deletePlan(int $planId): void
    {
        $this->assertPositive($planId, 'Lageplan');
        $statement = $this->pdo->prepare('SELECT storage_path FROM floor_plans WHERE id = :id FOR UPDATE');
        $this->pdo->beginTransaction();
        try {
            $statement->execute(['id' => $planId]);
            $storagePath = $statement->fetchColumn();
            if ($storagePath === false) {
                throw new DomainException('Der Lageplan existiert nicht.');
            }
            $this->pdo->prepare('DELETE FROM floor_plans WHERE id = :id')->execute(['id' => $planId]);
            $this->pdo->commit();
            $absolute = $this->absoluteStoragePath((string) $storagePath);
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{path:string,mime_type:string}|null */
    public function image(int $planId): ?array
    {
        if ($planId < 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT storage_path, mime_type FROM floor_plans WHERE id = :id AND active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $planId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $path = $this->absoluteStoragePath((string) $row['storage_path']);
        if (!is_file($path)) {
            return null;
        }

        return ['path' => $path, 'mime_type' => (string) $row['mime_type']];
    }

    /** @return list<array<string, mixed>> */
    private function groupsForFloor(int $floorId, int $planId, int $schoolYearId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT cg.id, cg.code, COALESCE(cg.name, cg.code) AS name, a.name AS area_name, '
            . 'p.x_percent, p.y_percent, p.width_percent, p.height_percent '
            . 'FROM cabinet_groups cg INNER JOIN areas a ON a.id = cg.area_id '
            . 'LEFT JOIN floor_plan_group_positions p ON p.cabinet_group_id = cg.id AND p.floor_plan_id = :plan_id '
            . 'WHERE a.floor_id = :floor_id AND cg.active = 1 AND a.active = 1 ORDER BY a.name, cg.code'
        );
        $statement->execute(['plan_id' => $planId, 'floor_id' => $floorId]);
        $groups = $statement->fetchAll(PDO::FETCH_ASSOC);

        $lockerStatement = $this->pdo->prepare(
            'SELECT cg.id AS group_id, c.position_no AS corpus_position, ct.name AS corpus_type, '
            . 'l.id AS locker_id, l.position_no AS locker_position, l.short_name, l.bookable, l.active, '
            . 'l.operating_status, lo.booking_id AS occupied_booking_id, rs.reservation_id '
            . 'FROM cabinet_groups cg INNER JOIN corpuses c ON c.cabinet_group_id = cg.id '
            . 'INNER JOIN corpus_types ct ON ct.id = c.corpus_type_id '
            . 'INNER JOIN lockers l ON l.corpus_id = c.id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.school_year_id = :school_year_id AND lo.locker_id = l.id '
            . 'LEFT JOIN reservation_slots rs ON rs.school_year_id = :school_year_id_2 AND rs.locker_id = l.id '
            . 'WHERE a.floor_id = :floor_id AND cg.active = 1 AND c.active = 1 '
            . 'ORDER BY cg.id, c.position_no, l.position_no'
        );
        $lockerStatement->execute([
            'school_year_id' => $schoolYearId,
            'school_year_id_2' => $schoolYearId,
            'floor_id' => $floorId,
        ]);
        $lockersByGroup = [];
        foreach ($lockerStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupId = (int) $row['group_id'];
            $technicalUnavailable = (int) $row['active'] !== 1
                || (int) $row['bookable'] !== 1
                || (string) $row['operating_status'] !== 'operational';
            $occupied = $row['occupied_booking_id'] !== null;
            $reserved = $row['reservation_id'] !== null;
            $availability = $technicalUnavailable ? 'unavailable' : (($occupied || $reserved) ? 'occupied' : 'free');
            $lockersByGroup[$groupId][] = [
                'id' => (int) $row['locker_id'],
                'short_name' => (string) $row['short_name'],
                'corpus_position' => (int) $row['corpus_position'],
                'corpus_type' => (string) $row['corpus_type'],
                'locker_position' => (int) $row['locker_position'],
                'operating_status' => (string) $row['operating_status'],
                'availability' => $availability,
                'occupied' => $occupied,
                'reserved' => $reserved,
            ];
        }

        $result = [];
        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            $lockers = $lockersByGroup[$groupId] ?? [];
            $free = 0;
            $occupied = 0;
            $unavailable = 0;
            foreach ($lockers as $locker) {
                if ($locker['availability'] === 'free') {
                    ++$free;
                } elseif ($locker['availability'] === 'unavailable') {
                    ++$unavailable;
                } else {
                    ++$occupied;
                }
            }
            $markerStatus = $unavailable > 0 ? 'warning' : ($free > 0 ? 'free' : 'full');
            $result[] = [
                'id' => $groupId,
                'code' => (string) $group['code'],
                'name' => (string) $group['name'],
                'area_name' => (string) $group['area_name'],
                'placed' => $group['x_percent'] !== null,
                'x_percent' => $group['x_percent'] !== null ? (float) $group['x_percent'] : null,
                'y_percent' => $group['y_percent'] !== null ? (float) $group['y_percent'] : null,
                'width_percent' => $group['width_percent'] !== null ? (float) $group['width_percent'] : 8.0,
                'height_percent' => $group['height_percent'] !== null ? (float) $group['height_percent'] : 8.0,
                'marker_status' => $markerStatus,
                'total_count' => count($lockers),
                'free_count' => $free,
                'occupied_count' => $occupied,
                'unavailable_count' => $unavailable,
                'lockers' => $lockers,
            ];
        }

        return $result;
    }

    private function insertPlan(
        int $floorId,
        string $title,
        string $storagePath,
        string $originalName,
        string $mime,
        int $staffUserId,
    ): int {
        $sort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM floor_plans WHERE floor_id = :floor_id');
        $sort->execute(['floor_id' => $floorId]);
        $sortOrder = (int) $sort->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO floor_plans '
            . '(floor_id, title, storage_path, original_name, mime_type, sort_order, active, created_by_staff_user_id, created_at, updated_at) '
            . 'VALUES (:floor_id, :title, :storage_path, :original_name, :mime_type, :sort_order, 1, :staff_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'floor_id' => $floorId,
            'title' => $title,
            'storage_path' => $storagePath,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'sort_order' => $sortOrder,
            'staff_id' => $staffUserId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Der Lageplan konnte nicht gespeichert werden.');
        }

        return $id;
    }

    private function assertFloor(int $floorId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM floors f INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE f.id = :id AND f.active = 1 AND b.active = 1'
        );
        $statement->execute(['id' => $floorId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Die Etage existiert nicht oder ist inaktiv.');
        }
    }

    private function groupBelongsToPlanFloor(int $planId, int $groupId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM floor_plans fp INNER JOIN floors f ON f.id = fp.floor_id '
            . 'INNER JOIN areas a ON a.floor_id = f.id INNER JOIN cabinet_groups cg ON cg.area_id = a.id '
            . 'WHERE fp.id = :plan_id AND cg.id = :group_id AND fp.active = 1 LIMIT 1'
        );
        $statement->execute(['plan_id' => $planId, 'group_id' => $groupId]);

        return $statement->fetchColumn() !== false;
    }

    private function storageDirectory(): string
    {
        $directory = $this->root . '/storage/floorplans';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Der Speicher für Lagepläne konnte nicht angelegt werden.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Der Speicher für Lagepläne ist nicht beschreibbar.');
        }

        return $directory;
    }

    private function absoluteStoragePath(string $storagePath): string
    {
        $storagePath = ltrim(str_replace('\\', '/', $storagePath), '/');
        if (!str_starts_with($storagePath, 'floorplans/') || str_contains($storagePath, '..')) {
            throw new RuntimeException('Ungültiger Lageplan-Speicherpfad.');
        }

        return $this->root . '/storage/' . $storagePath;
    }

    private function detectMime(string $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file);

        return is_string($mime) ? $mime : '';
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        if ($name === '') {
            return 'lageplan';
        }

        return mb_substr($name, 0, 255);
    }

    private function assertPositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label . ' ist ungültig.');
        }
    }
}
