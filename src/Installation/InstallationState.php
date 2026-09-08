<?php

declare(strict_types=1);

namespace FachDock\Installation;

final class InstallationState
{
    public function __construct(private readonly string $root)
    {
    }

    public function isInstalled(): bool
    {
        $file = $this->root . '/config/app.local.php';
        if (!is_file($file)) {
            return false;
        }

        $config = require $file;

        return is_array($config)
            && isset($config['app'])
            && is_array($config['app'])
            && ($config['app']['installed'] ?? false) === true;
    }
}
