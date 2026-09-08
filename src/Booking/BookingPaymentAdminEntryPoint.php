<?php

declare(strict_types=1);

namespace FachDock\Booking;

use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;

final class BookingPaymentAdminEntryPoint
{
    private const ROUTES = [
        'GET /admin/bookings',
        'GET /admin/bookings/detail',
        'GET /admin/payments',
        'GET /admin/payments/detail',
    ];

    public static function handles(Request $request): bool
    {
        return in_array($request->method() . ' ' . $request->path(), self::ROUTES, true);
    }

    public static function run(string $root, Request $request): void
    {
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $sessions = new StaffSessionService(
            $pdo,
            self::configInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::configInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $router = new Router();
        (new BookingPaymentAdminController(
            new BookingPaymentAdminService($pdo),
            $sessions,
            new ViewRenderer((string) $config->get('paths.templates')),
            new Csrf(),
        ))->register($router);

        $router->dispatch($request)->send();
    }

    private static function configInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
