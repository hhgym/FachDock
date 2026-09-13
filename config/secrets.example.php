<?php

declare(strict_types=1);

return [
    'database' => [
        'password' => '',
    ],
    'stripe' => [
        'test' => [
            'secret_key' => '',
            'webhook_secret' => '',
        ],
        'live' => [
            'secret_key' => '',
            'webhook_secret' => '',
        ],
    ],
    'smtp' => [
        'password' => '',
    ],
    'oidc' => [
        'client_secret' => '',
    ],
    'webpush' => [
        'private_key' => '',
    ],
];
