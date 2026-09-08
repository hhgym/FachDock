<?php

declare(strict_types=1);

namespace FachDock\Migration;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function migrate(): int
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedVersions();
        $migrations = $this->loadMigrations();
        $count = 0;

        foreach ($migrations as $migration) {
            if (isset($applied[$migration->version()])) {
                continue;
            }

            $migration->up($this->pdo);
            $statement = $this->pdo->prepare(
                'INSERT INTO sys_migrations (version, description, executed_at) VALUES (:version, :description, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'version' => $migration->version(),
                'description' => $migration->description(),
            ]);
            ++$count;
        }

        return $count;
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sys_migrations ('
            . 'version VARCHAR(32) NOT NULL PRIMARY KEY,'
            . 'description VARCHAR(255) NOT NULL,'
            . 'executed_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string, true> */
    private function appliedVersions(): array
    {
        $versions = [];
        $statement = $this->pdo->query('SELECT version FROM sys_migrations');
        if ($statement === false) {
            return $versions;
        }

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $version) {
            if (is_string($version)) {
                $versions[$version] = true;
            }
        }

        return $versions;
    }

    /** @return list<Migration> */
    private function loadMigrations(): array
    {
        $files = glob($this->directory . '/*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to read migration directory.');
        }
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException('Invalid migration: ' . basename($file));
            }
            $migrations[] = $migration;
        }

        usort(
            $migrations,
            static fn (Migration $a, Migration $b): int => strcmp($a->version(), $b->version()),
        );

        return $migrations;
    }
}
