<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Config\LocalConfigWriter;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class StripeSettingsController
{
    public function __construct(
        private readonly Config $config,
        private readonly LocalConfigWriter $writer,
        private readonly StaffSessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/config/stripe', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/config/stripe', fn (Request $request): Response => $this->save($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->page($staff, [], ($request->query()['saved'] ?? null) === '1');
    }

    private function save(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $mode = strtolower(trim($request->postString('mode')));
            if (!in_array($mode, ['test', 'live'], true)) {
                throw new RuntimeException('Der Stripe-Modus ist ungültig.');
            }

            $currency = strtoupper(trim($request->postString('currency')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new RuntimeException('Die Währung muss aus genau drei Buchstaben bestehen.');
            }

            $checkoutValue = trim($request->postString('checkout_minutes'));
            if (preg_match('/^\d+$/', $checkoutValue) !== 1) {
                throw new RuntimeException('Die Checkout-Gültigkeit muss eine ganze Zahl sein.');
            }
            $checkoutMinutes = (int) $checkoutValue;
            if ($checkoutMinutes < 30 || $checkoutMinutes > 1440) {
                throw new RuntimeException('Die Checkout-Gültigkeit muss zwischen 30 und 1440 Minuten liegen.');
            }

            $secretKey = trim($request->postString('secret_key'));
            $webhookSecret = trim($request->postString('webhook_secret'));
            $expectedPrefix = $mode === 'live' ? 'sk_live_' : 'sk_test_';
            $effectiveSecret = $secretKey !== ''
                ? $secretKey
                : trim((string) $this->config->get('stripe.secret_key', ''));

            if ($effectiveSecret !== '' && !str_starts_with($effectiveSecret, $expectedPrefix)) {
                throw new RuntimeException(
                    'Der Secret Key passt nicht zum gewählten Stripe-Modus. Erwartet wird ein Schlüssel mit ' . $expectedPrefix . '.',
                );
            }
            if ($webhookSecret !== '' && !str_starts_with($webhookSecret, 'whsec_')) {
                throw new RuntimeException('Das Webhook-Secret muss mit whsec_ beginnen.');
            }

            $this->writer->saveStripeSettings(
                $mode,
                $currency,
                $checkoutMinutes,
                $secretKey !== '' ? $secretKey : null,
                $webhookSecret !== '' ? $webhookSecret : null,
            );
            $this->audit->staff($staff, 'system.stripe.settings.updated', 'system', 'stripe', [
                'mode' => $mode,
                'currency' => $currency,
                'checkout_minutes' => $checkoutMinutes,
                'secret_key_replaced' => $secretKey !== '',
                'webhook_secret_replaced' => $webhookSecret !== '',
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/stripe?saved=1');
        } catch (RuntimeException $exception) {
            return $this->page($staff, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            $errorId = bin2hex(random_bytes(6));
            $this->logger->error('Stripe settings update failed', [
                'error_id' => $errorId,
                'staff_user_id' => $staff->id,
                'exception' => $exception,
            ]);

            return $this->page(
                $staff,
                ['Die Stripe-Konfiguration konnte nicht gespeichert werden. Fehler-ID: ' . $errorId],
                false,
                500,
            );
        }
    }

    /** @param list<string> $errors */
    private function page(AuthenticatedStaff $staff, array $errors, bool $success, int $status = 200): Response
    {
        $mode = strtolower((string) $this->config->get('stripe.mode', 'test'));
        $baseUrl = rtrim(trim((string) $this->config->get('app.base_url', '')), '/');

        return Response::html($this->views->render('stripe-settings.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'mode' => in_array($mode, ['test', 'live'], true) ? $mode : 'test',
            'currency' => strtoupper((string) $this->config->get('stripe.currency', 'EUR')),
            'checkoutMinutes' => (int) $this->config->get('stripe.checkout_minutes', 30),
            'secretConfigured' => trim((string) $this->config->get('stripe.secret_key', '')) !== '',
            'webhookConfigured' => trim((string) $this->config->get('stripe.webhook_secret', '')) !== '',
            'baseUrl' => $baseUrl,
            'webhookUrl' => $baseUrl !== '' ? $baseUrl . '/webhooks/stripe' : '',
            'baseUrlSecure' => str_starts_with($baseUrl, 'https://'),
        ]), $status);
    }

    private function administrator(): AuthenticatedStaff|Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        return $staff;
    }
}
