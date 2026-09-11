<?php

declare(strict_types=1);

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => 'FachDock',
        'version' => '1.0.0-rc.2',
        'environment' => 'production',
        'debug' => false,
        'installed' => false,
        'school_name' => '',
        'base_url' => '',
    ],
    'auth' => [
        'password_min_length' => 12,
        'max_failed_attempts' => 5,
        'lockout_minutes' => 15,
        'session_max_lifetime_minutes' => 480,
        'session_idle_timeout_minutes' => 60,
        'parent_magic_link_minutes' => 15,
        'parent_session_lifetime_minutes' => 1440,
    ],
    'oidc' => [
        'enabled' => false,
        'issuer' => '',
        'client_id' => '',
        'scopes' => 'openid profile email iserv:uuid iserv:groups iserv:roles',
        'student_auto_match' => 'email',
        'teacher_role_names' => 'Lehrer, Lehrkräfte',
        'session_lifetime_minutes' => 480,
    ],
    'booking' => [
        'recommendation_count' => 3,
        'reservation_minutes' => 15,
        'payment_grace_minutes' => 30,
        'but_rejection_payment_days' => 14,
        'self_service_change_limit' => 2,
    ],
    'stripe' => [
        'mode' => 'test',
        'currency' => 'EUR',
        'checkout_minutes' => 30,
    ],
    'mail' => [
        'worker_batch_size' => 50,
        'max_per_hour' => 50,
        'immediate_reserve_per_hour' => 10,
        'retry_minutes' => [15, 60, 360],
        'processing_timeout_minutes' => 15,
    ],
    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => 'FachDock',
    ],
    'updates' => [
        'default_channel' => 'stable',
        'allow_rc' => false,
        'allow_develop' => false,
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
