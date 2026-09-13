<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Booking\BookingService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Parent\ParentSessionService;
use Throwable;

final class StripeReturnFrontController
{
    private const RETURN_PATH = '/parent/payment/return';
    private const WEBHOOK_PATH = '/webhooks/stripe';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($path === self::WEBHOOK_PATH && $method === 'GET') {
            return Response::text(
                "FachDock Stripe webhook endpoint is reachable. Stripe events must be sent via POST with a valid Stripe-Signature header.\n",
            );
        }

        if ($path !== self::RETURN_PATH || $method !== 'GET') {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        if (is_file($root . '/storage/maintenance.flag')) {
            return null;
        }

        self::startSession();
        $request = Request::fromGlobals();
        $paymentId = self::positiveId(self::queryString($request, 'payment_id'));
        $sessionId = self::queryString($request, 'session_id');
        if ($paymentId === null || $sessionId === '') {
            return null;
        }

        try {
            $config = Config::load($root);
            $stripeState = StripeConfigurationState::fromConfig($config);
            if (!$stripeState->credentialsConfigured()) {
                return null;
            }

            $pdo = ConnectionFactory::fromConfig($config);
            $sessions = new ParentSessionService(
                $pdo,
                self::positiveInt($config, 'auth.parent_session_lifetime_minutes', 1440),
                self::positiveInt($config, 'auth.session_idle_timeout_minutes', 60),
            );
            $parent = $sessions->current();
            if ($parent === null) {
                return null;
            }

            $stripe = new StripePhpGateway(
                (string) $config->get('stripe.secret_key', ''),
                (string) $config->get('stripe.webhook_secret', ''),
                $stripeState->mode,
            );
            (new StripeReturnReconciler($pdo, $stripe, new BookingService($pdo)))
                ->reconcile($parent, $paymentId, $sessionId);
        } catch (Throwable $exception) {
            LoggerFactory::create($root)->warning('Stripe browser return reconciliation failed', [
                'payment_id' => $paymentId,
                'exception' => $exception,
            ]);
        }

        // The normal application controller renders the payment result page after
        // reconciliation. Returning null deliberately continues the regular routing.
        return null;
    }

    private static function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function positiveId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function positiveInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('fachdock');
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
