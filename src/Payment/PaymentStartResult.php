<?php

declare(strict_types=1);

namespace FachDock\Payment;

use LogicException;

final readonly class PaymentStartResult
{
    private function __construct(
        public ?int $paymentId,
        public ?int $bookingId,
        public ?string $checkoutUrl,
    ) {
    }

    public static function checkout(int $paymentId, string $checkoutUrl): self
    {
        return new self($paymentId, null, $checkoutUrl);
    }

    public static function booking(int $bookingId): self
    {
        return new self(null, $bookingId, null);
    }

    public function checkoutUrl(): string
    {
        if ($this->checkoutUrl === null) {
            throw new LogicException('Für dieses Ergebnis existiert keine Checkout-URL.');
        }

        return $this->checkoutUrl;
    }
}
