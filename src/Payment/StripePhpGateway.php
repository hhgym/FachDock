<?php

declare(strict_types=1);

namespace FachDock\Payment;

use DomainException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripePhpGateway implements BookingPaymentGateway
{
    private readonly StripeClient $client;

    public function __construct(
        string $secretKey,
        private readonly string $webhookSecret,
        string $mode = 'test',
    ) {
        $secretKey = trim($secretKey);
        if ($secretKey === '') {
            throw new DomainException('Stripe ist nicht vollständig konfiguriert.');
        }

        $mode = mb_strtolower(trim($mode));
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new DomainException('Der Stripe-Modus muss test oder live sein.');
        }
        $expectedPrefix = $mode === 'live' ? 'sk_live_' : 'sk_test_';
        if (!str_starts_with($secretKey, $expectedPrefix)) {
            throw new DomainException(
                $mode === 'live'
                    ? 'Für den Stripe-Live-Modus ist ein sk_live_-Schlüssel erforderlich.'
                    : 'Für den Stripe-Testmodus ist ein sk_test_-Schlüssel erforderlich.',
            );
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

        $customerData = [
            'email' => $email,
            'metadata' => [
                'fachdock_parent_contact_id' => (string) $parentContactId,
            ],
        ];
        if ($name !== null && trim($name) !== '') {
            $customerData['name'] = trim($name);
        }
        $customer = $this->client->customers->create($customerData, [
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
        return $this->createSession(
            $paymentId,
            $customerId,
            $amountCents,
            $currency,
            $description,
            $successUrl,
            $cancelUrl,
            $expiresAt,
            [
                'fachdock_payment_id' => (string) $paymentId,
                'fachdock_reservation_id' => (string) $reservationId,
            ],
        );
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
        return $this->createSession(
            $paymentId,
            $customerId,
            $amountCents,
            $currency,
            $description,
            $successUrl,
            $cancelUrl,
            $expiresAt,
            [
                'fachdock_payment_id' => (string) $paymentId,
                'fachdock_booking_id' => (string) $bookingId,
            ],
        );
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new DomainException('Die Stripe-Checkout-Session fehlt.');
        }

        $session = $this->client->checkout->sessions->retrieve($sessionId, []);
        $status = is_string($session->status) ? $session->status : '';
        if ($status === 'expired') {
            return;
        }
        if ($status === 'complete') {
            throw new DomainException(
                'Der Stripe-Checkout wurde bereits abgeschlossen und kann nicht als offen beendet werden.'
            );
        }
        if ($status !== 'open') {
            throw new DomainException('Der Stripe-Checkout befindet sich nicht mehr in einem beendbaren Zustand.');
        }

        $this->client->checkout->sessions->expire($sessionId, []);
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

    /**
     * @param array<string, string> $metadata
     */
    private function createSession(
        int $paymentId,
        string $customerId,
        int $amountCents,
        string $currency,
        string $description,
        string $successUrl,
        string $cancelUrl,
        int $expiresAt,
        array $metadata,
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
            'metadata' => $metadata,
            'payment_intent_data' => [
                'metadata' => $metadata,
            ],
        ], [
            'idempotency_key' => 'fachdock-payment-' . $paymentId,
        ]);

        if ($session->id === '' || !is_string($session->url) || $session->url === '') {
            throw new RuntimeException('Stripe hat keine gültige Checkout Session zurückgegeben.');
        }

        return new StripeCheckoutSession($session->id, $session->url);
    }
}
