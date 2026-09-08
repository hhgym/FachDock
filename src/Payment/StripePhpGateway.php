<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripePhpGateway implements StripeGateway
{
    private readonly StripeClient $client;

    public function __construct(
        string $secretKey,
        private readonly string $webhookSecret,
    ) {
        $secretKey = trim($secretKey);
        if ($secretKey === '') {
            throw new DomainException('Stripe ist nicht vollständig konfiguriert.');
        }
        $this->client = new StripeClient($secretKey);
    }

    public function ensureCustomer(
        int $parentContactId,
        string $email,
        ?string $name,
        ?string $existingCustomerId,
    ): string {
        if ($existingCustomerId !== null && trim($existingCustomerId) !== '') {
            return trim($existingCustomerId);
        }

        $customerParams = [
            'email' => $email,
            'metadata' => [
                'fachdock_parent_contact_id' => (string) $parentContactId,
            ],
        ];
        $name = $name !== null ? trim($name) : '';
        if ($name !== '') {
            $customerParams['name'] = $name;
        }

        $customer = $this->client->customers->create($customerParams, [
            'idempotency_key' => 'fachdock-parent-' . $parentContactId,
        ]);

        if ($customer->id === '') {
            throw new RuntimeException('Stripe hat keine gültige Customer-ID zurückgegeben.');
        }

        return $customer->id;
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
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customerId,
            'client_reference_id' => (string) $paymentId,
            'line_items' => [[
                'price_data' => [
                    'currency' => mb_strtolower($currency),
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => $description,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expiresAt,
            'metadata' => [
                'fachdock_payment_id' => (string) $paymentId,
                'fachdock_reservation_id' => (string) $reservationId,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'fachdock_payment_id' => (string) $paymentId,
                    'fachdock_reservation_id' => (string) $reservationId,
                ],
            ],
        ], [
            'idempotency_key' => 'fachdock-payment-' . $paymentId,
        ]);

        if ($session->id === '' || !is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe hat keine gültige Checkout Session zurückgegeben.');
        }

        return new StripeCheckoutSession($session->id, $session->url);
    }

    public function verifyWebhook(string $payload, string $signature): StripeWebhookEvent
    {
        if (trim($this->webhookSecret) === '') {
            throw new DomainException('Das Stripe-Webhook-Secret ist nicht konfiguriert.');
        }
        if ($payload === '' || $signature === '') {
            throw new DomainException('Der Stripe-Webhook ist unvollständig.');
        }

        $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        $object = $event->data->object;
        if (!$object instanceof Session) {
            throw new DomainException('Der Stripe-Webhook enthält keine Checkout Session.');
        }

        $paymentIntent = $object->payment_intent;
        $paymentIntentId = match (true) {
            is_string($paymentIntent) && $paymentIntent !== '' => $paymentIntent,
            $paymentIntent instanceof PaymentIntent => $paymentIntent->id,
            default => null,
        };

        return new StripeWebhookEvent(
            $event->id,
            $event->type,
            $object->id,
            $object->payment_status,
            $paymentIntentId,
        );
    }
}
