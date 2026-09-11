<?php

declare(strict_types=1);

namespace FachDock\Update;

use FachDock\Backup\BackupService;
use FachDock\Migration\MigrationRunner;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SelfUpdateService
{
    public function __construct(
        private readonly string $root,
        private readonly PDO $pdo,
        private readonly GitHubReleaseClient $client,
        private readonly DevelopBuildState $developBuildState,
    ) {
    }

    public function install(UpdateInfo $update, string $currentVersion, int $staffUserId): void
    {
        if (!$update->isAvailableFor($currentVersion, $this->developBuildState->currentBuildId())) {
            throw new RuntimeException('Die ausgewählte Version ist nicht neuer als der installierte Stand.');
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('Das FachDock-Installationsverzeichnis ist für den Webserver nicht beschreibbar.');
        }

        $storage = $this->root . '/storage';
        $workRoot = $storage . '/updates';
        $backupRoot = $storage . '/update-backups';
        $token = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $work = $workRoot . '/' . $update->version . '-' . $token;
        $backup = $backupRoot . '/' . $token;
        $zipFile = $work . '/FachDock-' . $update->version . '.zip';
        $extractDir = $work . '/extracted';
        $maintenanceFile = $storage . '/maintenance.flag';

        $this->ensureDirectory($work);
        $this->ensureDirectory($backup);
        $this->client->download($update->zipUrl, $zipFile);
        $checksumText = $this->client->text($update->checksumUrl);
        $this->verifyChecksum($zipFile, $checksumText);
        $this->extractSafely($zipFile, $extractDir);

        $packageRoot = $extractDir . '/FachDock-' . $update->version;
        if (!is_dir($packageRoot) || !is_file($packageRoot . '/public/index.php')) {
            throw new RuntimeException('Das Release-Paket besitzt nicht die erwartete FachDock-Struktur.');
        }

        $fullBackup = (new BackupService($this->root, $this->pdo))->create();

        file_put_contents(
            $maintenanceFile,
            json_encode([
                'target_version' => $update->version,
                'target_channel' => $update->channel->value,
                'target_build_id' => $update->buildId,
                'started_at' => date(DATE_ATOM),
                'backup' => $fullBackup['file'],
            ], JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        $manifest = ['overwritten' => [], 'created' => []];
        try {
            $this->deployPackage($packageRoot, $backup, $manifest);
            file_put_contents(
                $backup . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX,
            );

            (new MigrationRunner($this->pdo, $this->root . '/migrations'))->migrate();
            $this->writeAuditEntry($staffUserId, $currentVersion, $update, $fullBackup['file']);
            file_put_contents(
                $storage . '/last-update.json',
                json_encode([
                    'from' => $currentVersion,
                    'to' => $update->version,
                    'channel' => $update->channel->value,
                    'build_id' => $update->buildId,
                    'completed_at' => date(DATE_ATOM),
                    'file_backup' => basename($backup),
                    'full_backup' => $fullBackup['file'],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX,
            );

            if ($update->channel === UpdateChannel::Develop) {
                $this->developBuildState->markInstalled($update);
            } else {
                $this->developBuildState->clear();
            }

            @unlink($maintenanceFile);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->removeDirectory($work);
        } catch (Throwable $exception) {
            $this->restoreFiles($backup, $manifest);
            try {
                (new BackupService($this->root, $this->pdo))->restore($fullBackup['path']);
            } catch (Throwable $restoreException) {
                @unlink($maintenanceFile);
                throw new RuntimeException(
                    'Das Update ist fehlgeschlagen und das automatische Voll-Rollback konnte nicht abgeschlossen werden: '
                    . $restoreException->getMessage(),
                    0,
                    $exception,
                );
            }
            @unlink($maintenanceFile);
            throw $exception;
        }
    }

    /** @param array{overwritten: list<string>, created: list<string>} $manifest */
    private function deployPackage(string $packageRoot, string $backupRoot, array &$manifest): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $source = $item->getPathname();
            $relative = ltrim(substr($source, strlen($packageRoot)), '/\\');
            if ($this->isPreservedPath($relative)) {
                continue;
            }

            $target = $this->root . '/' . $relative;
            $this->ensureDirectory(dirname($target));

            if (is_file($target)) {
                $backup = $backupRoot . '/' . $relative;
                $this->ensureDirectory(dirname($backup));
                if (!copy($target, $backup)) {
                    throw new RuntimeException('Sicherung einer vorhandenen Datei ist fehlgeschlagen: ' . $relative);
                }
                $manifest['overwritten'][] = $relative;
            } else {
                $manifest['created'][] = $relative;
            }

            $temporary = $target . '.fachdock-update';
            if (!copy($source, $temporary)) {
                throw new RuntimeException('Update-Datei konnte nicht bereitgestellt werden: ' . $relative);
            }
            if (!rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Update-Datei konnte nicht aktiviert werden: ' . $relative);
            }
        }
    }

    /** @param array{overwritten: list<string>, created: list<string>} $manifest */
    private function restoreFiles(string $backupRoot, array $manifest): void
    {
        foreach (array_reverse($manifest['created']) as $relative) {
            @unlink($this->root . '/' . $relative);
        }
        foreach (array_reverse($manifest['overwritten']) as $relative) {
            $backup = $backupRoot . '/' . $relative;
            $target = $this->root . '/' . $relative;
            if (is_file($backup)) {
                @copy($backup, $target);
            }
        }
    }

    private function verifyChecksum(string $zipFile, string $checksumText): void
    {
        if (preg_match('/\b([a-fA-F0-9]{64})\b/', $checksumText, $matches) !== 1) {
            throw new RuntimeException('Die SHA-256-Prüfsumme des Releases ist ungültig.');
        }
        $actual = hash_file('sha256', $zipFile);
        if (!is_string($actual) || !hash_equals(strtolower($matches[1]), strtolower($actual))) {
            throw new RuntimeException('Die SHA-256-Prüfung des Release-Pakets ist fehlgeschlagen.');
        }
    }

    private function extractSafely(string $zipFile, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Das Release-ZIP konnte nicht geöffnet werden.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $name === '' || str_contains($name, "\0")) {
                    throw new RuntimeException('Das Release-ZIP enthält einen ungültigen Dateinamen.');
                }
                $normalized = str_replace('\\', '/', $name);
                if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
                    throw new RuntimeException('Das Release-ZIP enthält einen unsicheren Dateipfad.');
                }
            }
            $this->ensureDirectory($target);
            if (!$zip->extractTo($target)) {
                throw new RuntimeException('Das Release-ZIP konnte nicht entpackt werden.');
            }
        } finally {
            $zip->close();
        }
    }

    private function isPreservedPath(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        return $relative === 'config/app.local.php'
            || $relative === 'config/secrets.local.php'
            || $relative === 'storage/.gitkeep'
            || str_starts_with($relative, 'storage/');
    }

    private function writeAuditEntry(int $staffUserId, string $from, UpdateInfo $update, string $backupFile): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_type, staff_user_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :action, :entity_type, :entity_id, :metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => 'staff',
            'staff_user_id' => $staffUserId,
            'action' => 'system.update.completed',
            'entity_type' => 'system',
            'entity_id' => 'fachdock',
            'metadata' => json_encode([
                'from' => $from,
                'to' => $update->version,
                'channel' => $update->channel->value,
                'build_id' => $update->buildId,
                'backup' => $backupFile,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
