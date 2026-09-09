<?php

declare(strict_types=1);

namespace FachDock\Payment;

interface BookingPaymentGateway extends StripeGateway
{
    public function createBookingCheckoutSession(
        int $paymentId,
        int $bookingId,
        string $customerId,
        int $amountCents,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): StripeCheckoutSession;
}
