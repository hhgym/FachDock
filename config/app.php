<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => 'FachDock',
        'version' => '0.1.0-dev',
        'environment' => 'production',
        'debug' => false,
        'installed' => false,
        'school_name' => '',
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'fachdock',
        'username' => 'fachdock',
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'root' => $root,
        'storage' => $root . '/storage',
        'logs' => $root . '/storage/logs',
        'migrations' => $root . '/migrations',
        'templates' => $root . '/templates',
    ],
];
