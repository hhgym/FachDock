<?php

declare(strict_types=1);

namespace FachDock\Payment;

final readonly class StripeCheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
    }
}
