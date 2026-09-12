<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Audit\AuditLogger;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Logging\LoggerFactory;
use FachDock\Security\Csrf;
use PDO;
use Throwable;

final class PaymentAdminActionFrontController
{
    private const PATH = '/admin/payments/terminate';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== self::PATH) {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        self::startSession();
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $sessions = new StaffSessionService(
            $pdo,
            self::positiveInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::positiveInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $staff = $sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }

        $request = Request::fromGlobals();
        if ($request->method() !== 'POST') {
            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        }
        $csrf = new Csrf();
        if (!$csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $paymentId = self::positiveId($request->postString('payment_id'));
        if ($paymentId === null) {
            return Response::redirect('/admin/payments');
        }
        try {
            $reason = $request->postString('reason');
            self::expireRemoteCheckout($pdo, $config, $paymentId);
            (new AdminPaymentTerminationService($pdo))->terminate($paymentId, $reason, $staff->displayName);
            (new AuditLogger($pdo))->staff($staff, 'payment.open_attempt.terminated', 'payment', $paymentId, [
                'reason' => mb_substr(trim($reason), 0, 500),
            ]);
            $csrf->rotate();

            return Response::redirect('/admin/payments/detail?id=' . $paymentId . '&terminated=1');
        } catch (DomainException $exception) {
            return Response::redirect(
                '/admin/payments/detail?id=' . $paymentId . '&terminate_error=' . rawurlencode($exception->getMessage())
            );
        } catch (Throwable $exception) {
            LoggerFactory::create($root)->error('Open payment termination failed', [
                'payment_id' => $paymentId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return Response::redirect('/admin/payments/detail?id=' . $paymentId . '&terminate_error=technical');
        }
    }

    private static function expireRemoteCheckout(PDO $pdo, Config $config, int $paymentId): void
    {
        $statement = $pdo->prepare(
            'SELECT status, stripe_checkout_session_id FROM payments WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $paymentId]);
        $payment = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($payment)) {
            throw new DomainException('Der Zahlungsvorgang wurde nicht gefunden.');
        }
        if (!in_array((string) $payment['status'], [PaymentStatus::Creating->value, PaymentStatus::CheckoutOpen->value], true)) {
            throw new DomainException('Nur noch nicht bestätigte offene Zahlungsvorgänge können manuell beendet werden.');
        }

        $sessionId = trim((string) ($payment['stripe_checkout_session_id'] ?? ''));
        if ($sessionId === '') {
            return;
        }
        $secretKey = trim((string) $config->get('stripe.secret_key', ''));
        if ($secretKey === '') {
            throw new DomainException(
                'Die Stripe-Konfiguration fehlt. Der externe Checkout kann deshalb nicht sicher beendet werden.'
            );
        }

        (new StripePhpGateway(
            $secretKey,
            (string) $config->get('stripe.webhook_secret', ''),
            (string) $config->get('stripe.mode', 'test'),
        ))->expireCheckoutSession($sessionId);
    }

    private static function positiveId(string $value): ?int
    {
        $value = trim($value);

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
