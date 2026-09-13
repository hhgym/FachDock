<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Audit\AuditLogger;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Parent\ParentSessionService;
use FachDock\Security\Csrf;
use PDO;

final class StripePaymentResumeFrontController
{
    private const PATH = '/parent/payment/start';
    private const CHECKOUT_HANDOFF_KEY = 'stripe_checkout_handoff';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== self::PATH || (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        if (is_file($root . '/storage/maintenance.flag')) {
            return Response::html('<h1>FachDock wird aktualisiert.</h1><p>Bitte laden Sie die Seite in Kürze erneut.</p>', 503);
        }

        self::startSession();
        $request = Request::fromGlobals();
        if (trim($request->postString('booking_id')) !== '') {
            return null;
        }
        $reservationId = self::positiveId($request->postString('reservation_id'));
        if ($reservationId === null) {
            return null;
        }

        $config = Config::load($root);
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

        $csrf = new Csrf();
        if (!$csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }

        $checkout = self::openCheckout($pdo, $parent->id, $reservationId);
        if ($checkout === null) {
            return null;
        }

        $checkoutUrl = $checkout['checkout_url'];
        if (!self::validCheckoutUrl($checkoutUrl)) {
            return null;
        }

        (new AuditLogger($pdo))->parent(
            $parent,
            'payment.stripe_checkout.resumed',
            'payment',
            $checkout['payment_id'],
            ['reservation_id' => $reservationId],
        );

        $_SESSION[self::CHECKOUT_HANDOFF_KEY] = [
            'parent_contact_id' => $parent->id,
            'checkout_url' => $checkoutUrl,
            'expires_at' => time() + 300,
        ];

        return self::handoffPage();
    }

    /** @return array{payment_id:int,checkout_url:string}|null */
    private static function openCheckout(PDO $pdo, int $parentContactId, int $reservationId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT p.id AS payment_id, p.checkout_url '
            . 'FROM payment_attempt_slots pas '
            . 'INNER JOIN payments p ON p.id = pas.payment_id '
            . 'INNER JOIN locker_reservations lr ON lr.id = pas.reservation_id '
            . 'WHERE pas.reservation_id = :reservation_id '
            . 'AND p.parent_contact_id = :parent_contact_id '
            . "AND p.status = 'checkout_open' AND p.checkout_url IS NOT NULL "
            . "AND lr.status = 'payment_running' AND lr.payment_grace_expires_at > CURRENT_TIMESTAMP "
            . 'LIMIT 1'
        );
        $statement->execute([
            'reservation_id' => $reservationId,
            'parent_contact_id' => $parentContactId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'payment_id' => (int) $row['payment_id'],
            'checkout_url' => (string) $row['checkout_url'],
        ];
    }

    private static function handoffPage(): Response
    {
        $continueUrl = '/parent/payment/continue';

        return Response::html(
            '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="0;url=' . $continueUrl . '">'
            . '<title>Zahlung fortsetzen · FachDock</title></head><body>'
            . '<main><h1>Zahlung wird fortgesetzt</h1>'
            . '<p>Sie werden zurück zur bereits geöffneten Stripe-Zahlungsseite weitergeleitet.</p>'
            . '<p><a href="' . $continueUrl . '">Weiter zu Stripe</a></p>'
            . '</main></body></html>',
        );
    }

    private static function validCheckoutUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
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
