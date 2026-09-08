<?php

declare(strict_types=1);

namespace FachDock\Installation;

final class SystemRequirements
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public function check(): array
    {
        $requirements = [
            $this->item('php', 'PHP 8.3 oder neuer', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION),
        ];

        foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'] as $extension) {
            $requirements[] = $this->item(
                'ext_' . $extension,
                'PHP-Erweiterung ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'verfügbar' : 'fehlt',
            );
        }

        foreach (['config', 'storage'] as $directory) {
            $path = $this->root . '/' . $directory;
            $requirements[] = $this->item(
                'writable_' . $directory,
                'Verzeichnis ' . $directory . ' beschreibbar',
                is_dir($path) && is_writable($path),
                $path,
            );
        }

        return $requirements;
    }

    public function allMet(): bool
    {
        foreach ($this->check() as $requirement) {
            if (!$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function item(string $key, string $label, bool $ok, string $detail): array
    {
        return compact('key', 'label', 'ok', 'detail');
    }
}
