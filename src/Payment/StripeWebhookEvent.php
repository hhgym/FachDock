<?php

declare(strict_types=1);

namespace FachDock\Payment;

final readonly class StripeWebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public string $checkoutSessionId,
        public string $paymentStatus,
        public ?string $paymentIntentId,
    ) {
    }
}
