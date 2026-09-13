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
}
