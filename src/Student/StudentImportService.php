<?php

declare(strict_types=1);

namespace FachDock\Student;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class StudentImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AccessCodeGenerator $accessCodes = new AccessCodeGenerator(),
    ) {
    }

    /**
     * @param list<array{line:int, matrikelnummer:string, first_name:string, last_name:string, class_name:string, grade:int, email:?string, active:bool, category:string, messages:list<string>}> $rows
     */
    public function preview(array $rows, bool $fullImport = false): StudentImportPreview
    {
        $lookup = $this->pdo->prepare(
            'SELECT id, first_name, last_name, class_name, grade, email, active '
            . 'FROM students WHERE matrikelnummer = :matrikelnummer LIMIT 1'
        );
        $previewRows = [];
        $presentMatrikelnummern = [];

        foreach ($rows as $row) {
            if ($row['matrikelnummer'] !== '') {
                $presentMatrikelnummern[] = $row['matrikelnummer'];
            }

            if ($row['category'] === 'invalid') {
                $previewRows[] = $row;
                continue;
            }

            $lookup->execute(['matrikelnummer' => $row['matrikelnummer']]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                $row['category'] = 'new';
                $previewRows[] = $row;
                continue;
            }

            if ((int) $existing['active'] === 0 && $row['active']) {
                $row['category'] = 'reactivated';
            } elseif ($this->changed($existing, $row)) {
                $row['category'] = 'changed';
            } else {
                $row['category'] = 'unchanged';
            }
            $previewRows[] = $row;
        }

        return new StudentImportPreview(
            $previewRows,
            [],
            $fullImport ? $this->potentialDeactivations($presentMatrikelnummern) : [],
        );
    }

    /**
     * @return array{run_id:int, imported:int, deactivated:int, access_codes:list<array{matrikelnummer:string, first_name:string, last_name:string, class_name:string, access_code:string}>}
     * @throws JsonException
     */
    public function commit(
        StudentImportPreview $preview,
        int $staffUserId,
        string $sourceFilename,
        bool $fullImport,
        bool $skipInvalidRows,
        ?int $profileId = null,
    ): array {
        if ($preview->hasInvalidRows() && !$skipInvalidRows) {
            throw new RuntimeException('Der Import enthält ungültige Zeilen und wurde nicht durchgeführt.');
        }
        if ($fullImport && $preview->hasInvalidRows()) {
            throw new RuntimeException(
                'Bei einem vollständigen Import dürfen keine ungültigen Zeilen übersprungen werden, '
                . 'da sonst Schüler fälschlich deaktiviert werden könnten.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $runId = $this->createRun(
                $staffUserId,
                $sourceFilename,
                $fullImport ? 'full' : 'partial',
                $profileId,
            );
            $upsert = $this->pdo->prepare(
                'INSERT INTO students '
                . '(matrikelnummer, first_name, last_name, class_name, grade, email, active, inactive_since, access_code_hash, '
                . 'access_code_generated_at, last_import_run_id, created_at, updated_at) '
                . 'VALUES (:matrikelnummer, :first_name, :last_name, :class_name, :grade, :email, :active, :inactive_since, '
                . ':access_code_hash, :access_code_generated_at, :run_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), '
                . 'class_name = VALUES(class_name), grade = VALUES(grade), email = VALUES(email), '
                . 'inactive_since = CASE '
                . 'WHEN VALUES(active) = 1 THEN NULL '
                . 'WHEN active = 1 AND VALUES(active) = 0 THEN CURRENT_TIMESTAMP '
                . 'ELSE COALESCE(inactive_since, CURRENT_TIMESTAMP) END, '
                . "account_deactivated_at = CASE WHEN VALUES(active) = 1 AND account_deactivation_source = 'lifecycle' "
                . 'THEN NULL ELSE account_deactivated_at END, '
                . "account_deactivation_source = CASE WHEN VALUES(active) = 1 AND account_deactivation_source = 'lifecycle' "
                . 'THEN NULL ELSE account_deactivation_source END, '
                . 'active = VALUES(active), '
                . 'access_code_hash = COALESCE(access_code_hash, VALUES(access_code_hash)), '
                . 'access_code_generated_at = COALESCE(access_code_generated_at, VALUES(access_code_generated_at)), '
                . 'last_import_run_id = VALUES(last_import_run_id), updated_at = CURRENT_TIMESTAMP'
            );
            $lookupCode = $this->pdo->prepare(
                'SELECT access_code_hash FROM students WHERE matrikelnummer = :matrikelnummer LIMIT 1'
            );
            $reactivateOidc = $this->pdo->prepare(
                'UPDATE oidc_identities oi INNER JOIN students s ON s.id = oi.student_id '
                . 'SET oi.active = 1, oi.updated_at = CURRENT_TIMESTAMP '
                . 'WHERE s.matrikelnummer = :matrikelnummer AND s.active = 1 AND s.account_deactivated_at IS NULL '
                . "AND s.anonymized_at IS NULL AND oi.identity_type = 'student'"
            );

            $imported = 0;
            $matrikelnummern = [];
            $generatedCodes = [];

            foreach ($preview->rows as $row) {
                if ($row['category'] === 'invalid') {
                    continue;
                }

                $lookupCode->execute(['matrikelnummer' => $row['matrikelnummer']]);
                $existingHash = $lookupCode->fetchColumn();
                $plainCode = null;
                $hash = null;
                $generatedAt = null;
                if ($existingHash === false || $existingHash === null || (string) $existingHash === '') {
                    $plainCode = $this->uniqueAccessCode();
                    $hash = $this->accessCodes->hash($plainCode);
                    $generatedAt = date('Y-m-d H:i:s');
                }

                $upsert->execute([
                    'matrikelnummer' => $row['matrikelnummer'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'class_name' => $row['class_name'],
                    'grade' => $row['grade'],
                    'email' => $row['email'],
                    'active' => $row['active'] ? 1 : 0,
                    'inactive_since' => $row['active'] ? null : date('Y-m-d H:i:s'),
                    'access_code_hash' => $hash,
                    'access_code_generated_at' => $generatedAt,
                    'run_id' => $runId,
                ]);
                if ($row['active']) {
                    $reactivateOidc->execute(['matrikelnummer' => $row['matrikelnummer']]);
                }

                if ($plainCode !== null) {
                    $generatedCodes[] = [
                        'matrikelnummer' => $row['matrikelnummer'],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'class_name' => $row['class_name'],
                        'access_code' => $plainCode,
                    ];
                }
                $matrikelnummern[] = $row['matrikelnummer'];
                $imported++;
            }

            $deactivated = $fullImport ? $this->deactivateMissing($matrikelnummern, $runId) : 0;
            $summary = [
                'imported' => $imported,
                'deactivated' => $deactivated,
                'generated_access_codes' => count($generatedCodes),
                'preview_counts' => $preview->counts(),
                'profile_id' => $profileId,
            ];
            $this->completeRun($runId, $summary);
            if ($profileId !== null) {
                $this->markProfileUsed($profileId);
            }
            $this->audit($staffUserId, $runId, $summary);
            $this->pdo->commit();

            return [
                'run_id' => $runId,
                'imported' => $imported,
                'deactivated' => $deactivated,
                'access_codes' => $generatedCodes,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<string> $presentMatrikelnummern
     * @return list<array{matrikelnummer:string, first_name:string, last_name:string, class_name:string, grade:int}>
     */
    private function potentialDeactivations(array $presentMatrikelnummern): array
    {
        $params = [];
        $sql = 'SELECT matrikelnummer, first_name, last_name, class_name, grade FROM students WHERE active = 1';

        $unique = array_values(array_unique($presentMatrikelnummern));
        if ($unique !== []) {
            $placeholders = [];
            foreach ($unique as $index => $matrikelnummer) {
                $key = 'present_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $matrikelnummer;
            }
            $sql .= ' AND matrikelnummer NOT IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY class_name, last_name, first_name, matrikelnummer';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $deactivations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $deactivations[] = [
                'matrikelnummer' => (string) $row['matrikelnummer'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'class_name' => (string) $row['class_name'],
                'grade' => (int) $row['grade'],
            ];
        }

        return $deactivations;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{first_name:string,last_name:string,class_name:string,grade:int,email:?string,active:bool} $row
     */
    private function changed(array $existing, array $row): bool
    {
        return (string) $existing['first_name'] !== $row['first_name']
            || (string) $existing['last_name'] !== $row['last_name']
            || (string) $existing['class_name'] !== $row['class_name']
            || (int) $existing['grade'] !== $row['grade']
            || $this->nullableString($existing['email'] ?? null) !== $row['email']
            || ((int) $existing['active'] === 1) !== $row['active'];
    }

    private function uniqueAccessCode(): string
    {
        $lookup = $this->pdo->prepare('SELECT id FROM students WHERE access_code_hash = :hash LIMIT 1');
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->accessCodes->generate();
            $lookup->execute(['hash' => $this->accessCodes->hash($code)]);
            if ($lookup->fetchColumn() === false) {
                return $code;
            }
        }

        throw new RuntimeException('Es konnte kein eindeutiger Schüler-Zugangscode erzeugt werden.');
    }

    private function createRun(int $staffUserId, string $filename, string $mode, ?int $profileId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO student_import_runs '
            . '(staff_user_id, profile_id, source_filename, mode, status, summary, created_at) '
            . 'VALUES (:staff_user_id, :profile_id, :filename, :mode, :status, :summary, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'staff_user_id' => $staffUserId,
            'profile_id' => $profileId,
            'filename' => mb_substr(basename($filename), 0, 255),
            'mode' => $mode,
            'status' => 'processing',
            'summary' => '{}',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Importlauf konnte nicht angelegt werden.');
        }

        return $id;
    }

    /** @param list<string> $matrikelnummern */
    private function deactivateMissing(array $matrikelnummern, int $runId): int
    {
        if ($matrikelnummern === []) {
            throw new RuntimeException('Ein vollständiger Import darf nicht leer sein.');
        }

        $placeholders = [];
        $params = ['run_id' => $runId];
        foreach ($matrikelnummern as $index => $matrikelnummer) {
            $key = 'm' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $matrikelnummer;
        }

        $statement = $this->pdo->prepare(
            'UPDATE students SET active = 0, inactive_since = COALESCE(inactive_since, CURRENT_TIMESTAMP), '
            . 'last_import_run_id = :run_id, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE active = 1 AND matrikelnummer NOT IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $summary @throws JsonException */
    private function completeRun(int $runId, array $summary): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE student_import_runs SET status = :status, summary = :summary, completed_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute([
            'id' => $runId,
            'status' => 'completed',
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function markProfileUsed(int $profileId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE student_import_profiles SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => $profileId]);
        if ($statement->rowCount() === 0) {
            $check = $this->pdo->prepare('SELECT id FROM student_import_profiles WHERE id = :id AND active = 1');
            $check->execute(['id' => $profileId]);
            if ($check->fetchColumn() === false) {
                throw new RuntimeException('Das verwendete Importprofil ist nicht mehr verfügbar.');
            }
        }
    }

    /** @param array<string, mixed> $summary @throws JsonException */
    private function audit(int $staffUserId, int $runId, array $summary): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log '
            . '(actor_type, staff_user_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :action, :entity_type, :entity_id, :metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => 'staff',
            'staff_user_id' => $staffUserId,
            'action' => 'students.import.completed',
            'entity_type' => 'student_import_run',
            'entity_id' => (string) $runId,
            'metadata' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        return (string) $value;
    }
}
