<?php

declare(strict_types=1);

namespace FachDock\Backup;

use JsonException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class BackupService
{
    public function __construct(
        private readonly string $root,
        private readonly PDO $pdo,
    ) {
    }

    /** @return array{path:string,file:string,tables:int,rows:int,files:int,sha256:string} */
    public function create(): array
    {
        $directory = $this->root . '/storage/backups';
        $this->ensureDirectory($directory);
        $token = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $file = 'fachdock-backup-' . $token . '.zip';
        $path = $directory . '/' . $file;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Die Backup-Datei konnte nicht angelegt werden.');
        }

        $tableCount = 0;
        $rowCount = 0;
        $fileCount = 0;
        $snapshotStarted = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->beginTransaction();
                $snapshotStarted = true;
            }

            $tables = $this->tables();
            foreach ($tables as $table) {
                $schema = $this->createStatement($table);
                $rows = $this->rows($table);
                $tableCount++;
                $rowCount += count($rows);
                if (!$zip->addFromString('database/schema/' . $table . '.sql', $schema . ";\n")) {
                    throw new RuntimeException('Das Schema konnte nicht in das Backup geschrieben werden: ' . $table);
                }
                if (!$zip->addFromString(
                    'database/data/' . $table . '.json',
                    json_encode(
                        $rows,
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                )) {
                    throw new RuntimeException('Die Daten konnten nicht in das Backup geschrieben werden: ' . $table);
                }
            }

            if ($snapshotStarted) {
                $this->pdo->commit();
                $snapshotStarted = false;
            }

            foreach ($this->persistentFiles() as $absolute => $relative) {
                if (!$zip->addFile($absolute, 'files/' . $relative)) {
                    throw new RuntimeException('Eine persistente Datei konnte nicht gesichert werden: ' . $relative);
                }
                $fileCount++;
            }

            $manifest = [
                'format' => 1,
                'created_at' => date(DATE_ATOM),
                'php_version' => PHP_VERSION,
                'tables' => $tables,
                'table_count' => $tableCount,
                'row_count' => $rowCount,
                'file_count' => $fileCount,
            ];
            if (!$zip->addFromString(
                'manifest.json',
                json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            )) {
                throw new RuntimeException('Das Backup-Manifest konnte nicht geschrieben werden.');
            }
        } catch (Throwable $exception) {
            if ($snapshotStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $zip->close();
            @unlink($path);
            throw $exception;
        }

        if (!$zip->close()) {
            @unlink($path);
            throw new RuntimeException('Die Backup-Datei konnte nicht abgeschlossen werden.');
        }

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)) {
            throw new RuntimeException('Die Prüfsumme des Backups konnte nicht berechnet werden.');
        }
        if (file_put_contents($path . '.sha256', $sha256 . '  ' . $file . "\n", LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Die Backup-Prüfsumme konnte nicht gespeichert werden.');
        }

        return [
            'path' => $path,
            'file' => $file,
            'tables' => $tableCount,
            'rows' => $rowCount,
            'files' => $fileCount,
            'sha256' => $sha256,
        ];
    }

    /** @return array{tables:int,rows:int,files:int} */
    public function restore(string $archivePath): array
    {
        $archivePath = $this->resolveArchivePath($archivePath);
        if (!is_file($archivePath)) {
            throw new RuntimeException('Das angegebene Backup existiert nicht.');
        }
        $this->verifyChecksumIfPresent($archivePath);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Das Backup-ZIP konnte nicht geöffnet werden.');
        }

        try {
            $manifest = $this->decodeJson($zip->getFromName('manifest.json'), 'Backup-Manifest');
            if (($manifest['format'] ?? null) !== 1
                || !is_array($manifest['tables'] ?? null)
                || !array_is_list($manifest['tables'])
            ) {
                throw new RuntimeException('Das Backup-Format wird nicht unterstützt.');
            }

            $tables = [];
            foreach ($manifest['tables'] as $table) {
                if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                    throw new RuntimeException('Das Backup enthält einen ungültigen Tabellennamen.');
                }
                if (in_array($table, $tables, true)) {
                    throw new RuntimeException('Das Backup enthält einen Tabellennamen mehrfach.');
                }
                $tables[] = $table;
            }

            $schemas = [];
            $data = [];
            foreach ($tables as $table) {
                $schema = $zip->getFromName('database/schema/' . $table . '.sql');
                if (!is_string($schema) || trim($schema) === '') {
                    throw new RuntimeException('Im Backup fehlt das Schema für ' . $table . '.');
                }
                $schemas[$table] = $this->validatedCreateStatement($table, $schema);
                $rows = $this->decodeJson(
                    $zip->getFromName('database/data/' . $table . '.json'),
                    'Tabellendaten ' . $table,
                );
                if (!array_is_list($rows)) {
                    throw new RuntimeException('Die Tabellendaten für ' . $table . ' sind ungültig.');
                }
                $data[$table] = $rows;
            }

            $files = $this->readRestoreFiles($zip);
            $this->restoreDatabase($tables, $schemas, $data);
            $this->clearPersistentStorageFiles();
            $this->restoreFiles($files);

            return [
                'tables' => count($tables),
                'rows' => array_sum(array_map('count', $data)),
                'files' => count($files),
            ];
        } finally {
            $zip->close();
        }
    }

    private function verifyChecksumIfPresent(string $archivePath): void
    {
        $checksumFile = $archivePath . '.sha256';
        if (!is_file($checksumFile)) {
            return;
        }
        $checksumText = file_get_contents($checksumFile);
        if (!is_string($checksumText) || preg_match('/\b([a-fA-F0-9]{64})\b/', $checksumText, $matches) !== 1) {
            throw new RuntimeException('Die Backup-Prüfsumme ist ungültig.');
        }
        $actual = hash_file('sha256', $archivePath);
        if (!is_string($actual) || !hash_equals(strtolower($matches[1]), strtolower($actual))) {
            throw new RuntimeException('Die Backup-Prüfsumme stimmt nicht überein.');
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $statement = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if ($statement === false) {
            throw new RuntimeException('Die Datenbanktabellen konnten nicht ermittelt werden.');
        }
        $tables = [];
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $table = (string) ($row[0] ?? '');
            if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('Ungültiger Tabellenname in der Datenbank.');
            }
            $tables[] = $table;
        }
        sort($tables);

        return $tables;
    }

    private function createStatement(string $table): string
    {
        $statement = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
            throw new RuntimeException('Das Schema der Tabelle ' . $table . ' konnte nicht gelesen werden.');
        }

        return $row[1];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $table): array
    {
        $statement = $this->pdo->query('SELECT * FROM `' . $table . '`');
        if ($statement === false) {
            throw new RuntimeException('Die Tabelle ' . $table . ' konnte nicht gesichert werden.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,string> absolute => relative */
    private function persistentFiles(): array
    {
        $files = [];
        foreach (['config/app.local.php', 'config/secrets.local.php'] as $relative) {
            $absolute = $this->root . '/' . $relative;
            if (is_file($absolute) && !is_link($absolute)) {
                $files[$absolute] = $relative;
            }
        }

        $storage = $this->root . '/storage';
        if (!is_dir($storage)) {
            return $files;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = 'storage/' . ltrim(substr($item->getPathname(), strlen($storage)), '/\\');
            $normalized = str_replace('\\', '/', $relative);
            if ($this->isExcludedStoragePath($normalized)) {
                continue;
            }
            $files[$item->getPathname()] = $normalized;
        }

        return $files;
    }

    /**
     * @param list<string> $tables
     * @param array<string,string> $schemas
     * @param array<string,list<mixed>> $data
     */
    private function restoreDatabase(array $tables, array $schemas, array $data): void
    {
        $currentTables = $this->tables();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_reverse($currentTables) as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            foreach ($tables as $table) {
                $this->pdo->exec($schemas[$table]);
            }
            foreach ($tables as $table) {
                foreach ($data[$table] as $row) {
                    if (!is_array($row) || $row === []) {
                        continue;
                    }
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (!is_string($column) || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                            throw new RuntimeException('Ungültige Spalte im Backup der Tabelle ' . $table . '.');
                        }
                    }
                    $quotedColumns = implode(
                        ', ',
                        array_map(static fn (string $column): string => '`' . $column . '`', $columns),
                    );
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $statement = $this->pdo->prepare(
                        'INSERT INTO `' . $table . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')',
                    );
                    $statement->execute(array_values($row));
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function validatedCreateStatement(string $table, string $schema): string
    {
        $schema = rtrim(trim($schema), ';');
        $expected = '/^CREATE\s+TABLE\s+`' . preg_quote($table, '/') . '`\s*\(/i';
        if (preg_match($expected, $schema) !== 1 || str_contains($schema, "\0")) {
            throw new RuntimeException('Das Tabellenschema im Backup ist ungültig: ' . $table . '.');
        }
        if (preg_match('/;\s*(?:ALTER|CREATE|DELETE|DROP|GRANT|INSERT|RENAME|REVOKE|SET|TRUNCATE|UPDATE)\b/i', $schema) === 1) {
            throw new RuntimeException('Das Tabellenschema im Backup enthält unerwartete SQL-Anweisungen.');
        }

        return $schema;
    }

    /** @return array<string,string> */
    private function readRestoreFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !str_starts_with($name, 'files/')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($name, 6));
            if (!$this->isAllowedRestorePath($relative)) {
                throw new RuntimeException('Das Backup enthält einen unsicheren Dateipfad.');
            }
            if (array_key_exists($relative, $files)) {
                throw new RuntimeException('Das Backup enthält einen Dateipfad mehrfach.');
            }
            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                throw new RuntimeException('Eine Backup-Datei konnte nicht gelesen werden: ' . $relative);
            }
            $files[$relative] = $contents;
        }

        return $files;
    }

    /** @param array<string,string> $files */
    private function restoreFiles(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->assertNoSymlinkComponents($relative);
            $target = $this->root . '/' . $relative;
            $this->ensureDirectory(dirname($target));
            if (file_put_contents($target, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Eine Backup-Datei konnte nicht wiederhergestellt werden: ' . $relative);
            }
        }
    }

    private function clearPersistentStorageFiles(): void
    {
        $storage = $this->root . '/storage';
        if (!is_dir($storage)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink()) {
                continue;
            }
            $relative = 'storage/' . ltrim(substr($item->getPathname(), strlen($storage)), '/\\');
            $normalized = str_replace('\\', '/', $relative);
            if ($this->isExcludedStoragePath($normalized)) {
                continue;
            }
            if ($item->isFile()) {
                if (!@unlink($item->getPathname()) && is_file($item->getPathname())) {
                    throw new RuntimeException('Eine persistente Datei konnte vor dem Restore nicht entfernt werden.');
                }
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }

    private function isAllowedRestorePath(string $relative): bool
    {
        if ($relative === ''
            || str_contains($relative, "\0")
            || str_starts_with($relative, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1
        ) {
            return false;
        }

        return $relative === 'config/app.local.php'
            || $relative === 'config/secrets.local.php'
            || (str_starts_with($relative, 'storage/') && !$this->isExcludedStoragePath($relative));
    }

    private function isExcludedStoragePath(string $relative): bool
    {
        return $relative === 'storage/maintenance.flag'
            || $relative === 'storage/backups'
            || str_starts_with($relative, 'storage/backups/')
            || $relative === 'storage/updates'
            || str_starts_with($relative, 'storage/updates/')
            || $relative === 'storage/update-backups'
            || str_starts_with($relative, 'storage/update-backups/');
    }

    private function assertNoSymlinkComponents(string $relative): void
    {
        $segments = explode('/', str_replace('\\', '/', $relative));
        $path = rtrim($this->root, '/\\');
        foreach (array_slice($segments, 0, -1) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException('Der Restore-Zielpfad enthält einen symbolischen Link.');
            }
        }
    }

    /** @return array<mixed> */
    private function decodeJson(string|false $json, string $label): array
    {
        if (!is_string($json)) {
            throw new RuntimeException($label . ' fehlt im Backup.');
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label . ' ist ungültig.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException($label . ' ist ungültig.');
        }

        return $value;
    }

    private function resolveArchivePath(string $archivePath): string
    {
        $archivePath = trim($archivePath);
        if ($archivePath === '') {
            throw new RuntimeException('Bitte eine Backup-Datei angeben.');
        }
        if (!str_contains($archivePath, '/') && !str_contains($archivePath, '\\')) {
            return $this->root . '/storage/backups/' . $archivePath;
        }

        return $archivePath;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
        }
        if (is_link($directory)) {
            throw new RuntimeException('Ein benötigtes Verzeichnis darf kein symbolischer Link sein: ' . $directory);
        }
    }
}
