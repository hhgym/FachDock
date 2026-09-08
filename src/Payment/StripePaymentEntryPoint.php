<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Audit\AuditLogger;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\BookingService;
use FachDock\Booking\FeeCalculator;
use FachDock\Booking\ProjectedGradeResolver;
use FachDock\Booking\ReservationService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Router;
use FachDock\Logging\LoggerFactory;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;

final class StripePaymentEntryPoint
{
    private const ROUTES = [
        'POST /parent/payment/start',
        'GET /parent/payment/return',
        'POST /webhooks/stripe',
    ];

    public static function handles(Request $request): bool
    {
        return in_array($request->method() . ' ' . $request->path(), self::ROUTES, true);
    }

    public static function run(string $root, Request $request): void
    {
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $logger = LoggerFactory::create($root);
        $views = new ViewRenderer((string) $config->get('paths.templates'));
        $csrf = new Csrf();
        $allocationEvaluator = new AllocationRuleEvaluator($pdo);
        $reservations = new ReservationService(
            $pdo,
            self::configInt($config, 'booking.reservation_minutes', 15),
            self::configInt($config, 'booking.payment_grace_minutes', 30),
            $allocationEvaluator,
            new ProjectedGradeResolver(),
        );
        $payments = new StripePaymentService(
            $pdo,
            new StripePhpGateway(
                (string) $config->get('stripe.secret_key', ''),
                (string) $config->get('stripe.webhook_secret', ''),
                (string) $config->get('stripe.mode', 'test'),
            ),
            $reservations,
            new BookingService($pdo),
            new FeeCalculator(),
            (string) $config->get('app.base_url', ''),
            (string) $config->get('stripe.currency', 'EUR'),
            self::configInt($config, 'stripe.checkout_minutes', 30),
        );
        $sessions = new ParentSessionService(
            $pdo,
            self::configInt($config, 'auth.parent_session_lifetime_minutes', 1440),
        );
        $router = new Router();
        (new StripePaymentController(
            $payments,
            $sessions,
            new AuditLogger($pdo),
            $logger,
            $views,
            $csrf,
        ))->register($router);

        $router->dispatch($request)->send();
    }

    private static function configInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
