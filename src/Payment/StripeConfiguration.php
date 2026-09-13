<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use FachDock\Config\Config;

final readonly class StripeConfiguration
{
    public function __construct(
        public string $mode,
        public string $secretKey,
        public string $webhookSecret,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $mode = mb_strtolower(trim((string) $config->get('stripe.mode', 'test')));
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new DomainException('Der Stripe-Modus muss test oder live sein.');
        }

        $secretKey = self::value($config, 'stripe.' . $mode . '.secret_key');
        $webhookSecret = self::value($config, 'stripe.' . $mode . '.webhook_secret');

        if ($secretKey === '' && $webhookSecret === '') {
            $secretKey = self::value($config, 'stripe.secret_key');
            $webhookSecret = self::value($config, 'stripe.webhook_secret');
        }

        return new self($mode, $secretKey, $webhookSecret);
    }

    private static function value(Config $config, string $key): string
    {
        return trim((string) $config->get($key, ''));
    }
}
