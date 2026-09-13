<?php

declare(strict_types=1);

namespace FachDock\Payment;

final readonly class StripeCheckoutState
{
    public function __construct(
        public string $sessionId,
        public string $paymentStatus,
        public ?string $paymentIntentId,
    ) {
    }
}
