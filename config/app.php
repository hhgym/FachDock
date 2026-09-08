<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => 'FachDock',
        'version' => '0.2.0',
        'environment' => 'production',
        'debug' => false,
        'installed' => false,
        'school_name' => '',
    ],
    'auth' => [
        'password_min_length' => 12,
        'max_failed_attempts' => 5,
        'lockout_minutes' => 15,
        'session_max_lifetime_minutes' => 480,
        'session_idle_timeout_minutes' => 60,
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
