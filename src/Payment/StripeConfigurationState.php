<?php

declare(strict_types=1);

namespace FachDock\Payment;

use FachDock\Config\Config;

final readonly class StripeConfigurationState
{
    public function __construct(
        public string $mode,
        public bool $secretConfigured,
        public bool $webhookConfigured,
        public bool $baseUrlSecure,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $mode = strtolower(trim((string) $config->get('stripe.mode', 'test')));
        if (!in_array($mode, ['test', 'live'], true)) {
            $mode = 'test';
        }

        $secret = trim((string) $config->get('stripe.secret_key', ''));
        $webhookSecret = trim((string) $config->get('stripe.webhook_secret', ''));
        $baseUrl = rtrim(trim((string) $config->get('app.base_url', '')), '/');
        $expectedPrefix = $mode === 'live' ? 'sk_live_' : 'sk_test_';

        return new self(
            $mode,
            $secret !== '' && str_starts_with($secret, $expectedPrefix),
            $webhookSecret !== '' && str_starts_with($webhookSecret, 'whsec_'),
            str_starts_with($baseUrl, 'https://'),
        );
    }

    public function credentialsConfigured(): bool
    {
        return $this->secretConfigured && $this->webhookConfigured;
    }

    public function checkoutAvailable(): bool
    {
        return $this->credentialsConfigured() && $this->baseUrlSecure;
    }

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];
        if (!$this->secretConfigured) {
            $problems[] = 'Stripe Secret Key fehlt oder passt nicht zum gewählten Modus.';
        }
        if (!$this->webhookConfigured) {
            $problems[] = 'Stripe Webhook-Secret fehlt oder ist ungültig.';
        }
        if (!$this->baseUrlSecure) {
            $problems[] = 'Die Basis-URL ist nicht als HTTPS-Adresse konfiguriert.';
        }

        return $problems;
    }
}
