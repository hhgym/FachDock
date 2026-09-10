<?php

declare(strict_types=1);

namespace FachDock\Backup;

use JsonException;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
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
        try {
            $tables = $this->tables();
            foreach ($tables as $table) {
                $schema = $this->createStatement($table);
                $rows = $this->rows($table);
                $tableCount++;
                $rowCount += count($rows);
                $zip->addFromString('database/schema/' . $table . '.sql', $schema . ";\n");
                $zip->addFromString(
                    'database/data/' . $table . '.json',
                    json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
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
            $zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable $exception) {
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
        file_put_contents($path . '.sha256', $sha256 . '  ' . $file . "\n", LOCK_EX);

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

        $checksumFile = $archivePath . '.sha256';
        if (is_file($checksumFile)) {
            $checksumText = (string) file_get_contents($checksumFile);
            if (preg_match('/\b([a-fA-F0-9]{64})\b/', $checksumText, $matches) !== 1) {
                throw new RuntimeException('Die Backup-Prüfsumme ist ungültig.');
            }
            $actual = hash_file('sha256', $archivePath);
            if (!is_string($actual) || !hash_equals(strtolower($matches[1]), strtolower($actual))) {
                throw new RuntimeException('Die Backup-Prüfsumme stimmt nicht überein.');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Das Backup-ZIP konnte nicht geöffnet werden.');
        }

        try {
            $manifest = $this->decodeJson($zip->getFromName('manifest.json'), 'Backup-Manifest');
            if (($manifest['format'] ?? null) !== 1 || !is_array($manifest['tables'] ?? null)) {
                throw new RuntimeException('Das Backup-Format wird nicht unterstützt.');
            }
            $tables = [];
            foreach ($manifest['tables'] as $table) {
                if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                    throw new RuntimeException('Das Backup enthält einen ungültigen Tabellennamen.');
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
                $schemas[$table] = rtrim(trim($schema), ';');
                $rows = $this->decodeJson($zip->getFromName('database/data/' . $table . '.json'), 'Tabellendaten ' . $table);
                if (!array_is_list($rows)) {
                    throw new RuntimeException('Die Tabellendaten für ' . $table . ' sind ungültig.');
                }
                $data[$table] = $rows;
            }

            $this->restoreDatabase($tables, $schemas, $data);
            $fileCount = $this->restoreFiles($zip);

            return [
                'tables' => count($tables),
                'rows' => array_sum(array_map('count', $data)),
                'files' => $fileCount,
            ];
        } finally {
            $zip->close();
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
            if (is_file($absolute)) {
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
            if (!$item->isFile()) {
                continue;
            }
            $relative = 'storage/' . ltrim(substr($item->getPathname(), strlen($storage)), '/\\');
            $normalized = str_replace('\\', '/', $relative);
            if ($normalized === 'storage/maintenance.flag'
                || str_starts_with($normalized, 'storage/backups/')
                || str_starts_with($normalized, 'storage/updates/')
                || str_starts_with($normalized, 'storage/update-backups/')
            ) {
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
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_reverse($tables) as $table) {
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
                    $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $statement = $this->pdo->prepare(
                        'INSERT INTO `' . $table . '` (' . $quotedColumns . ') VALUES (' . $placeholders . ')'
                    );
                    $statement->execute(array_values($row));
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function restoreFiles(ZipArchive $zip): int
    {
        $count = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || !str_starts_with($name, 'files/')) {
                continue;
            }
            $relative = substr($name, 6);
            if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1
                || (!$this->isAllowedRestorePath($relative))
            ) {
                throw new RuntimeException('Das Backup enthält einen unsicheren Dateipfad.');
            }
            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                throw new RuntimeException('Eine Backup-Datei konnte nicht gelesen werden: ' . $relative);
            }
            $target = $this->root . '/' . $relative;
            $this->ensureDirectory(dirname($target));
            if (file_put_contents($target, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Eine Backup-Datei konnte nicht wiederhergestellt werden: ' . $relative);
            }
            $count++;
        }

        return $count;
    }

    private function isAllowedRestorePath(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        return $relative === 'config/app.local.php'
            || $relative === 'config/secrets.local.php'
            || str_starts_with($relative, 'storage/');
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
    }
}
