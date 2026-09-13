<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use PHPUnit\Framework\TestCase;

final class StripePaymentControllerHandoffTest extends TestCase
{
    public function testStripeCheckoutUsesSameOriginHandoffBeforeExternalNavigation(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Payment/StripePaymentController.php',
        );

        self::assertStringContainsString("\$router->get('/parent/payment/continue'", $controller);
        self::assertStringContainsString('CHECKOUT_HANDOFF_KEY', $controller);
        self::assertStringContainsString("'<meta http-equiv=\"refresh\" content=\"0;url='", $controller);
        self::assertStringContainsString("\$continueUrl = '/parent/payment/continue';", $controller);
        self::assertStringContainsString('return $this->checkoutHandoff($parent, $result->checkoutUrl());', $controller);
        self::assertStringNotContainsString('Response::redirect($result->checkoutUrl(), 303)', $controller);
    }

    public function testStartingCheckoutDoesNotRotateCsrfTokenNeededByBrowserBackNavigation(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Payment/StripePaymentController.php',
        );

        self::assertStringNotContainsString('$this->csrf->rotate();', $controller);
    }

    public function testOpenCheckoutCanBeResumedBeforeApplicationRouting(): void
    {
        $root = dirname(__DIR__, 2);
        $frontController = (string) file_get_contents(
            $root . '/src/Payment/StripePaymentResumeFrontController.php',
        );
        $index = (string) file_get_contents($root . '/public/index.php');

        self::assertStringContainsString('StripePaymentResumeFrontController::handle($root)', $index);
        self::assertStringContainsString("p.status = 'checkout_open'", $frontController);
        self::assertStringContainsString("lr.status = 'payment_running'", $frontController);
        self::assertStringContainsString('lr.payment_grace_expires_at > CURRENT_TIMESTAMP', $frontController);
        self::assertStringContainsString('payment.stripe_checkout.resumed', $frontController);
        self::assertStringContainsString("'stripe_checkout_handoff'", $frontController);
        self::assertStringContainsString('Zahlung wird fortgesetzt', $frontController);
    }
}
