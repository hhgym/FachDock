<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use FachDock\Payment\StripePhpGateway;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class StripeWebhookVerificationTest extends TestCase
{
    public function testSignedCheckoutSessionEventIsMapped(): void
    {
        $secret = 'whsec_fachdock_test';
        $payload = $this->checkoutEventPayload();
        $signature = Webhook::generateTestHeaderString([
            'payload' => $payload,
            'secret' => $secret,
            'timestamp' => time(),
        ]);
        $gateway = new StripePhpGateway('sk_test_example', $secret, 'test');

        $event = $gateway->verifyWebhook($payload, $signature);

        self::assertSame('evt_test_fachdock', $event->id);
        self::assertSame('checkout.session.completed', $event->type);
        self::assertSame('cs_test_fachdock', $event->checkoutSessionId);
        self::assertSame('paid', $event->paymentStatus);
        self::assertSame('pi_test_fachdock', $event->paymentIntentId);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $gateway = new StripePhpGateway('sk_test_example', 'whsec_fachdock_test', 'test');

        $this->expectException(SignatureVerificationException::class);
        $gateway->verifyWebhook($this->checkoutEventPayload(), 't=1,v1=invalid');
    }

    private function checkoutEventPayload(): string
    {
        return json_encode([
            'id' => 'evt_test_fachdock',
            'object' => 'event',
            'api_version' => '2025-12-15.clover',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'cs_test_fachdock',
                    'object' => 'checkout.session',
                    'mode' => 'payment',
                    'payment_intent' => 'pi_test_fachdock',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => [
                'id' => null,
                'idempotency_key' => null,
            ],
            'type' => 'checkout.session.completed',
        ], JSON_THROW_ON_ERROR);
    }
}
