<?php

declare(strict_types=1);

use FachDock\Application;
use FachDock\Booking\BookingPaymentAdminEntryPoint;
use FachDock\Http\Request;
use FachDock\Payment\StripePaymentEntryPoint;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FachDock dependencies are missing. Install the release package or run Composer.\n";
    exit;
}

require $autoload;

$app = Application::boot($root);
$request = Request::fromGlobals();
if (StripePaymentEntryPoint::handles($request)) {
    StripePaymentEntryPoint::run($root, $request);

    exit;
}
if (BookingPaymentAdminEntryPoint::handles($request)) {
    BookingPaymentAdminEntryPoint::run($root, $request);

    exit;
}

$app->run();
