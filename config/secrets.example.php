<?php

declare(strict_types=1);

/**
 * Copy this file to config/secrets.local.php and fill in local secrets.
 * Never commit secrets.local.php.
 */
return [
    'stripe' => [
        'secret_key' => '',
        'webhook_secret' => '',
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
