<?php

declare(strict_types=1);

use FachDock\Application;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FachDock dependencies are missing. Install the release package or run Composer.\n";
    exit;
}

require $autoload;

Application::boot($root)->run();
