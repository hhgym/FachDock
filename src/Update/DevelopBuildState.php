<?php

declare(strict_types=1);

namespace FachDock\Update;

use JsonException;
use RuntimeException;

final class DevelopBuildState
{
    private readonly string $file;

    public function __construct(string $root)
    {
        $this->file = rtrim($root, '/\\') . '/storage/develop-build.json';
    }

    /** @return array{build_id: string, version: string, installed_at: string}|null */
    public function current(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }

        $content = file_get_contents($this->file);
        if (!is_string($content) || $content === '') {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $buildId = $data['build_id'] ?? null;
        $version = $data['version'] ?? null;
        $installedAt = $data['installed_at'] ?? null;
        if (!is_string($buildId) || preg_match('/^[a-f0-9]{40}$/', $buildId) !== 1
            || !is_string($version) || $version === ''
            || !is_string($installedAt) || $installedAt === '') {
            return null;
        }

        return [
            'build_id' => $buildId,
            'version' => $version,
            'installed_at' => $installedAt,
        ];
    }

    public function currentBuildId(): ?string
    {
        return $this->current()['build_id'] ?? null;
    }

    public function markInstalled(UpdateInfo $update): void
    {
        if ($update->channel !== UpdateChannel::Develop || $update->buildId === null) {
            throw new RuntimeException('Nur Develop-Builds können als installierter Develop-Stand gespeichert werden.');
        }

        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Der Develop-Build-Status konnte nicht gespeichert werden.');
        }

        $temporary = $this->file . '.tmp';
        $content = json_encode([
            'build_id' => $update->buildId,
            'version' => $update->version,
            'installed_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('Der Develop-Build-Status konnte nicht gespeichert werden.');
        }
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
