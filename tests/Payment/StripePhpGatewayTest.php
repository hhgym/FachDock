<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Payment\StripePhpGateway;
use PHPUnit\Framework\TestCase;

final class StripePhpGatewayTest extends TestCase
{
    public function testTestModeAcceptsTestKey(): void
    {
        $gateway = new StripePhpGateway('sk_test_example', 'whsec_example', 'test');

        self::assertInstanceOf(StripePhpGateway::class, $gateway);
    }

    public function testTestModeRejectsLiveKey(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sk_test_');

        new StripePhpGateway('sk_live_example', 'whsec_example', 'test');
    }

    public function testLiveModeAcceptsLiveKey(): void
    {
        $gateway = new StripePhpGateway('sk_live_example', 'whsec_example', 'live');

        self::assertInstanceOf(StripePhpGateway::class, $gateway);
    }

    public function testLiveModeRejectsTestKey(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sk_live_');

        new StripePhpGateway('sk_test_example', 'whsec_example', 'live');
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('test oder live');

        new StripePhpGateway('sk_test_example', 'whsec_example', 'staging');
    }
}
