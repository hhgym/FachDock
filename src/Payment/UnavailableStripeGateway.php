<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;

final class UnavailableStripeGateway implements BookingPaymentGateway
{
    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string {
        unset($parentContactId, $email, $name, $existingCustomerId);

        throw new DomainException('Die Online-Zahlung ist derzeit nicht vollständig eingerichtet.');
    }

    public function createCheckoutSession(
        int $paymentId,
        int $reservationId,
        string $customerId,
        int $amountCents,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
    ): StripeCheckoutSession {
        unset(
            $paymentId,
            $reservationId,
            $customerId,
            $amountCents,
            $currency,
            $description,
            $successUrl,
            $cancelUrl,
            $expiresAt,
        );

        throw new DomainException('Die Online-Zahlung ist derzeit nicht vollständig eingerichtet.');
    }

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
    ): StripeCheckoutSession {
        unset(
            $paymentId,
            $bookingId,
            $customerId,
            $amountCents,
            $currency,
            $description,
            $successUrl,
            $cancelUrl,
            $expiresAt,
        );

        throw new DomainException('Die Online-Zahlung ist derzeit nicht vollständig eingerichtet.');
    }

    public function verifyWebhook(string $payload, string $signature): StripeWebhookEvent
    {
        unset($payload, $signature);

        throw new DomainException('Stripe-Webhooks sind derzeit nicht vollständig eingerichtet.');
    }
}
