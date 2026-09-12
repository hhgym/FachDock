<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Config\LocalConfigWriter;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Mail\MailMessage;
use FachDock\Mail\PhpMailerSmtpSender;
use FachDock\Security\Csrf;
use FachDock\System\WorkerHeartbeatService;
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
        $router->get('/admin/config', fn (Request $request): Response => $this->overview($request));
        $router->get('/admin/config/general', fn (Request $request): Response => $this->general($request));
        $router->post('/admin/config/general', fn (Request $request): Response => $this->saveGeneral($request));
        $router->get('/admin/config/auth', fn (Request $request): Response => $this->auth($request));
        $router->post('/admin/config/auth', fn (Request $request): Response => $this->saveAuth($request));
        $router->get('/admin/config/booking', fn (Request $request): Response => $this->booking($request));
        $router->post('/admin/config/booking', fn (Request $request): Response => $this->saveBooking($request));
        $router->get('/admin/config/mail', fn (Request $request): Response => $this->mail($request));
        $router->post('/admin/config/mail', fn (Request $request): Response => $this->saveMail($request));
        $router->post('/admin/config/mail/test', fn (Request $request): Response => $this->testMail($request));
        $router->get('/admin/config/stripe', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/config/stripe', fn (Request $request): Response => $this->saveStripe($request));
    }

    private function overview(Request $request): Response
    {
        unset($request);
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        $baseUrl = trim((string) $this->config->get('app.base_url', ''));
        $smtpHost = trim((string) $this->config->get('smtp.host', ''));
        $smtpFrom = trim((string) $this->config->get('smtp.from_email', ''));
        $stripeSecret = trim((string) $this->config->get('stripe.secret_key', ''));
        $stripeWebhook = trim((string) $this->config->get('stripe.webhook_secret', ''));

        return Response::html($this->views->render('config-index.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'schoolName' => (string) $this->config->get('app.school_name', ''),
            'baseUrlSecure' => str_starts_with($baseUrl, 'https://'),
            'smtpConfigured' => $smtpHost !== '' && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) !== false,
            'stripeConfigured' => $stripeSecret !== '' && $stripeWebhook !== '' && str_starts_with($baseUrl, 'https://'),
            'stripeMode' => strtolower((string) $this->config->get('stripe.mode', 'test')),
        ]));
    }

    private function general(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->generalPage($staff, [], $this->saved($request));
    }

    private function saveGeneral(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->generalPage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $schoolName = trim($request->postString('school_name'));
            if ($schoolName === '' || mb_strlen($schoolName) > 160) {
                throw new RuntimeException('Der Schulname muss zwischen 1 und 160 Zeichen lang sein.');
            }
            $baseUrl = $this->normalizeBaseUrl($request->postString('base_url'));

            $this->writer->saveGeneralSettings($schoolName, $baseUrl);
            $this->audit->staff($staff, 'system.general.settings.updated', 'system', 'general', [
                'school_name' => $schoolName,
                'base_url' => $baseUrl,
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/general?saved=1');
        } catch (RuntimeException $exception) {
            return $this->generalPage($staff, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'general', 'Allgemeine Konfiguration', $exception, fn (array $errors): Response => $this->generalPage($staff, $errors, false, 500));
        }
    }

    private function auth(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->authPage($staff, [], $this->saved($request));
    }

    private function saveAuth(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->authPage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $settings = [
                'password_min_length' => $this->integer($request, 'password_min_length', 8, 128, 'Passwort-Mindestlänge'),
                'max_failed_attempts' => $this->integer($request, 'max_failed_attempts', 1, 20, 'Maximale Fehlversuche'),
                'lockout_minutes' => $this->integer($request, 'lockout_minutes', 1, 1440, 'Sperrdauer'),
                'session_max_lifetime_minutes' => $this->integer($request, 'session_max_lifetime_minutes', 15, 10080, 'Maximale Sitzungsdauer'),
                'session_idle_timeout_minutes' => $this->integer($request, 'session_idle_timeout_minutes', 5, 1440, 'Inaktivitätslimit'),
                'parent_magic_link_minutes' => $this->integer($request, 'parent_magic_link_minutes', 5, 1440, 'Magic-Link-Gültigkeit'),
                'parent_session_lifetime_minutes' => $this->integer($request, 'parent_session_lifetime_minutes', 15, 10080, 'Eltern-Sitzungsdauer'),
            ];
            if ($settings['session_idle_timeout_minutes'] > $settings['session_max_lifetime_minutes']) {
                throw new RuntimeException('Das Inaktivitätslimit darf die maximale Sitzungsdauer nicht überschreiten.');
            }

            $this->writer->saveAuthSettings($settings);
            $this->audit->staff($staff, 'system.auth.settings.updated', 'system', 'auth', $settings);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/auth?saved=1');
        } catch (RuntimeException $exception) {
            return $this->authPage($staff, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'auth', 'Anmeldekonfiguration', $exception, fn (array $errors): Response => $this->authPage($staff, $errors, false, 500));
        }
    }

    private function booking(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->bookingPage($staff, [], $this->saved($request));
    }

    private function saveBooking(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->bookingPage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
        }

        try {
            $settings = [
                'recommendation_count' => $this->integer($request, 'recommendation_count', 1, 20, 'Anzahl Empfehlungen'),
                'reservation_minutes' => $this->integer($request, 'reservation_minutes', 5, 120, 'Reservierungsdauer'),
                'payment_grace_minutes' => $this->integer($request, 'payment_grace_minutes', 5, 180, 'Zahlungs-Gnadenfrist'),
                'but_rejection_payment_days' => $this->integer($request, 'but_rejection_payment_days', 1, 90, 'Zahlungsfrist nach BuT-Ablehnung'),
                'default_annual_fee_cents' => $this->moneyToCents($request->postString('default_annual_fee')),
            ];

            $this->writer->saveBookingSettings($settings);
            $this->audit->staff($staff, 'system.booking.settings.updated', 'system', 'booking', $settings);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/booking?saved=1');
        } catch (RuntimeException $exception) {
            return $this->bookingPage($staff, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'booking', 'Buchungskonfiguration', $exception, fn (array $errors): Response => $this->bookingPage($staff, $errors, false, 500));
        }
    }

    private function mail(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->mailPage($staff, [], $this->saved($request), false);
    }

    private function saveMail(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->mailPage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, false, 419);
        }

        try {
            $maxPerHour = $this->integer($request, 'max_per_hour', 1, 10000, '60-Minuten-Versandlimit');
            $mail = [
                'worker_batch_size' => $this->integer($request, 'worker_batch_size', 1, 500, 'Worker-Batch-Größe'),
                'max_per_hour' => $maxPerHour,
                'immediate_reserve_per_hour' => $this->integer(
                    $request,
                    'immediate_reserve_per_hour',
                    0,
                    $maxPerHour,
                    'Reserve für Sofortmails',
                ),
                'retry_minutes' => $this->retryMinutes($request->postString('retry_minutes')),
                'processing_timeout_minutes' => $this->integer($request, 'processing_timeout_minutes', 1, 120, 'Processing-Timeout'),
            ];
            $smtp = $this->smtpSettings($request);
            $password = trim($request->postString('smtp_password'));

            $this->writer->saveMailSettings($mail, $smtp, $password !== '' ? $password : null);
            $this->audit->staff($staff, 'system.mail.settings.updated', 'system', 'mail', [
                'worker_batch_size' => $mail['worker_batch_size'],
                'max_per_hour' => $mail['max_per_hour'],
                'immediate_reserve_per_hour' => $mail['immediate_reserve_per_hour'],
                'retry_minutes' => $mail['retry_minutes'],
                'processing_timeout_minutes' => $mail['processing_timeout_minutes'],
                'smtp_host' => $smtp['host'],
                'smtp_port' => $smtp['port'],
                'smtp_username' => $smtp['username'],
                'smtp_encryption' => $smtp['encryption'],
                'smtp_from_email' => $smtp['from_email'],
                'smtp_from_name' => $smtp['from_name'],
                'smtp_password_replaced' => $password !== '',
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/mail?saved=1');
        } catch (RuntimeException $exception) {
            return $this->mailPage($staff, [$exception->getMessage()], false, false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'mail', 'E-Mail-Konfiguration', $exception, fn (array $errors): Response => $this->mailPage($staff, $errors, false, false, 500));
        }
    }

    private function testMail(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->mailPage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, false, 419);
        }

        try {
            $recipient = trim($request->postString('test_recipient'));
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Bitte eine gültige Empfängeradresse für die Test-E-Mail angeben.');
            }

            $sender = new PhpMailerSmtpSender(
                trim((string) $this->config->get('smtp.host', '')),
                (int) $this->config->get('smtp.port', 587),
                trim((string) $this->config->get('smtp.username', '')),
                (string) $this->config->get('smtp.password', ''),
                trim((string) $this->config->get('smtp.encryption', 'tls')),
                trim((string) $this->config->get('smtp.from_email', '')),
                trim((string) $this->config->get('smtp.from_name', 'FachDock')),
            );
            $schoolName = trim((string) $this->config->get('app.school_name', ''));
            $sender->send(new MailMessage(
                $recipient,
                null,
                'FachDock SMTP-Test',
                '<p>Diese Test-E-Mail bestätigt, dass FachDock den konfigurierten SMTP-Server erfolgreich verwenden konnte.</p>',
                'Diese Test-E-Mail bestätigt, dass FachDock den konfigurierten SMTP-Server erfolgreich verwenden konnte.',
            ));
            $this->audit->staff($staff, 'system.mail.test_sent', 'system', 'mail', [
                'recipient' => $recipient,
                'school_name' => $schoolName,
            ]);
            $this->csrf->rotate();

            return $this->mailPage($staff, [], false, true);
        } catch (RuntimeException $exception) {
            return $this->mailPage($staff, [$exception->getMessage()], false, false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'mail_test', 'SMTP-Test', $exception, fn (array $errors): Response => $this->mailPage($staff, $errors, false, false, 500));
        }
    }

    private function index(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }

        return $this->stripePage($staff, [], $this->saved($request));
    }

    private function saveStripe(Request $request): Response
    {
        $staff = $this->administrator();
        if ($staff instanceof Response) {
            return $staff;
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->stripePage($staff, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], false, 419);
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

            $checkoutMinutes = $this->integer($request, 'checkout_minutes', 30, 1440, 'Checkout-Gültigkeit');
            $baseUrl = $this->normalizeBaseUrl($request->postString('base_url'));
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
                $baseUrl,
            );
            $this->audit->staff($staff, 'system.stripe.settings.updated', 'system', 'stripe', [
                'mode' => $mode,
                'currency' => $currency,
                'checkout_minutes' => $checkoutMinutes,
                'base_url' => $baseUrl,
                'secret_key_replaced' => $secretKey !== '',
                'webhook_secret_replaced' => $webhookSecret !== '',
            ]);
            $this->csrf->rotate();

            return Response::redirect('/admin/config/stripe?saved=1');
        } catch (RuntimeException $exception) {
            return $this->stripePage($staff, [$exception->getMessage()], false, 422);
        } catch (Throwable $exception) {
            return $this->configurationFailure($staff, 'stripe', 'Stripe-Konfiguration', $exception, fn (array $errors): Response => $this->stripePage($staff, $errors, false, 500));
        }
    }

    /** @param list<string> $errors */
    private function generalPage(AuthenticatedStaff $staff, array $errors, bool $success, int $status = 200): Response
    {
        return Response::html($this->views->render('config-general.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'schoolName' => (string) $this->config->get('app.school_name', ''),
            'baseUrl' => rtrim(trim((string) $this->config->get('app.base_url', '')), '/'),
        ]), $status);
    }

    /** @param list<string> $errors */
    private function authPage(AuthenticatedStaff $staff, array $errors, bool $success, int $status = 200): Response
    {
        return Response::html($this->views->render('config-auth.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'passwordMinLength' => (int) $this->config->get('auth.password_min_length', 12),
            'maxFailedAttempts' => (int) $this->config->get('auth.max_failed_attempts', 5),
            'lockoutMinutes' => (int) $this->config->get('auth.lockout_minutes', 15),
            'sessionMaxLifetimeMinutes' => (int) $this->config->get('auth.session_max_lifetime_minutes', 480),
            'sessionIdleTimeoutMinutes' => (int) $this->config->get('auth.session_idle_timeout_minutes', 60),
            'parentMagicLinkMinutes' => (int) $this->config->get('auth.parent_magic_link_minutes', 15),
            'parentSessionLifetimeMinutes' => (int) $this->config->get('auth.parent_session_lifetime_minutes', 1440),
        ]), $status);
    }

    /** @param list<string> $errors */
    private function bookingPage(AuthenticatedStaff $staff, array $errors, bool $success, int $status = 200): Response
    {
        $defaultAnnualFeeCents = max(0, (int) $this->config->get('booking.default_annual_fee_cents', 0));

        return Response::html($this->views->render('config-booking.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'recommendationCount' => (int) $this->config->get('booking.recommendation_count', 3),
            'reservationMinutes' => (int) $this->config->get('booking.reservation_minutes', 15),
            'paymentGraceMinutes' => (int) $this->config->get('booking.payment_grace_minutes', 30),
            'butRejectionPaymentDays' => (int) $this->config->get('booking.but_rejection_payment_days', 14),
            'defaultAnnualFee' => number_format($defaultAnnualFeeCents / 100, 2, ',', ''),
        ]), $status);
    }

    /** @param list<string> $errors */
    private function mailPage(AuthenticatedStaff $staff, array $errors, bool $success, bool $testSuccess, int $status = 200): Response
    {
        $retryMinutes = $this->config->get('mail.retry_minutes', [15, 60, 360]);
        $retryMinutes = is_array($retryMinutes) ? array_map('intval', $retryMinutes) : [15, 60, 360];
        $smtpHost = trim((string) $this->config->get('smtp.host', ''));
        $smtpFrom = trim((string) $this->config->get('smtp.from_email', ''));
        $mailWorkerStatus = (new WorkerHeartbeatService(
            ConnectionFactory::fromConfig($this->config),
            'mail',
        ))->status();

        return Response::html($this->views->render('config-mail.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $success,
            'testSuccess' => $testSuccess,
            'workerBatchSize' => (int) $this->config->get('mail.worker_batch_size', 50),
            'maxPerHour' => (int) $this->config->get('mail.max_per_hour', 50),
            'immediateReservePerHour' => max(0, (int) $this->config->get('mail.immediate_reserve_per_hour', 10)),
            'retryMinutes' => implode(', ', $retryMinutes),
            'processingTimeoutMinutes' => (int) $this->config->get('mail.processing_timeout_minutes', 15),
            'smtpHost' => $smtpHost,
            'smtpPort' => (int) $this->config->get('smtp.port', 587),
            'smtpUsername' => (string) $this->config->get('smtp.username', ''),
            'smtpEncryption' => strtolower((string) $this->config->get('smtp.encryption', 'tls')),
            'smtpFromEmail' => $smtpFrom,
            'smtpFromName' => (string) $this->config->get('smtp.from_name', 'FachDock'),
            'smtpPasswordConfigured' => trim((string) $this->config->get('smtp.password', '')) !== '',
            'smtpConfigured' => $smtpHost !== '' && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) !== false,
            'mailWorkerStatus' => $mailWorkerStatus,
        ]), $status);
    }

    /** @param list<string> $errors */
    private function stripePage(AuthenticatedStaff $staff, array $errors, bool $success, int $status = 200): Response
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

    /** @return array{host:string,port:int,username:string,encryption:string,from_email:string,from_name:string} */
    private function smtpSettings(Request $request): array
    {
        $host = trim($request->postString('smtp_host'));
        $port = $this->integer($request, 'smtp_port', 1, 65535, 'SMTP-Port');
        $username = trim($request->postString('smtp_username'));
        $encryption = strtolower(trim($request->postString('smtp_encryption')));
        $fromEmail = trim($request->postString('smtp_from_email'));
        $fromName = trim($request->postString('smtp_from_name'));

        if (!in_array($encryption, ['tls', 'smtps', 'none'], true)) {
            throw new RuntimeException('Die SMTP-Verschlüsselung ist ungültig.');
        }
        if ($host === '' && $fromEmail === '') {
            return [
                'host' => '',
                'port' => $port,
                'username' => $username,
                'encryption' => $encryption,
                'from_email' => '',
                'from_name' => $fromName !== '' ? $fromName : 'FachDock',
            ];
        }
        if ($host === '') {
            throw new RuntimeException('Für den E-Mail-Versand ist ein SMTP-Host erforderlich.');
        }
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Die SMTP-Absenderadresse ist ungültig.');
        }
        if ($fromName === '' || mb_strlen($fromName) > 160) {
            throw new RuntimeException('Der SMTP-Absendername muss zwischen 1 und 160 Zeichen lang sein.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'encryption' => $encryption,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    }

    /** @return list<int> */
    private function retryMinutes(string $value): array
    {
        $parts = preg_split('/[;,\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === [] || count($parts) > 10) {
            throw new RuntimeException('Bitte zwischen 1 und 10 Retry-Abstände in Minuten angeben.');
        }

        $minutes = [];
        foreach ($parts as $part) {
            if (preg_match('/^\d+$/', $part) !== 1) {
                throw new RuntimeException('Retry-Abstände müssen ganze Minutenwerte sein.');
            }
            $minute = (int) $part;
            if ($minute < 1 || $minute > 10080) {
                throw new RuntimeException('Retry-Abstände müssen zwischen 1 Minute und 7 Tagen liegen.');
            }
            $minutes[] = $minute;
        }
        $minutes = array_values(array_unique($minutes));
        sort($minutes, SORT_NUMERIC);

        return $minutes;
    }

    private function moneyToCents(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new RuntimeException('Der Standard-Jahresbeitrag ist ungültig.');
        }

        $euros = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ($euros * 100) + (int) $fraction;
        if ($cents > 100000000) {
            throw new RuntimeException('Der Standard-Jahresbeitrag ist zu hoch.');
        }

        return $cents;
    }

    private function integer(Request $request, string $field, int $min, int $max, string $label): int
    {
        $value = trim($request->postString($field));
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException($label . ' muss eine ganze Zahl sein.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new RuntimeException($label . ' muss zwischen ' . $min . ' und ' . $max . ' liegen.');
        }

        return $number;
    }

    private function normalizeBaseUrl(string $value): string
    {
        $baseUrl = rtrim(trim($value), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Die öffentliche Basis-URL ist erforderlich.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Die öffentliche Basis-URL ist keine gültige URL.');
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Die öffentliche Basis-URL muss mit https:// beginnen.');
        }
        if (trim((string) ($parts['host'] ?? '')) === '') {
            throw new RuntimeException('Die öffentliche Basis-URL muss einen gültigen Hostnamen enthalten.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Die öffentliche Basis-URL darf keine Zugangsdaten, Query-Parameter oder Fragmente enthalten.');
        }

        return $baseUrl;
    }

    private function saved(Request $request): bool
    {
        return ($request->query()['saved'] ?? null) === '1';
    }

    /** @param callable(list<string>): Response $page */
    private function configurationFailure(
        AuthenticatedStaff $staff,
        string $area,
        string $label,
        Throwable $exception,
        callable $page,
    ): Response {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error($label . ' update failed', [
            'error_id' => $errorId,
            'area' => $area,
            'staff_user_id' => $staff->id,
            'exception' => $exception,
        ]);

        return $page(['Die Änderung konnte nicht gespeichert werden. Fehler-ID: ' . $errorId]);
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
