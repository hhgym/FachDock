<?php

declare(strict_types=1);

namespace FachDock\Payment;

interface StripeGateway
{
    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string;

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
    ): StripeCheckoutSession;

    public function verifyWebhook(string $payload, string $signature): StripeWebhookEvent;
}
