<?php

declare(strict_types=1);

namespace FachDock\Tests\Security;

use FachDock\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testBaselineHeadersAreAlwaysPresent(): void
    {
        $headers = SecurityHeaders::forRequest([]);

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testHstsIsOnlyAddedForHttpsIncludingTrustedProxyHeader(): void
    {
        self::assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::forRequest(['HTTPS' => 'on']));
        self::assertArrayHasKey(
            'Strict-Transport-Security',
            SecurityHeaders::forRequest(['HTTP_X_FORWARDED_PROTO' => 'https']),
        );
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            SecurityHeaders::forRequest(['HTTP_X_FORWARDED_PROTO' => 'http']),
        );
    }
}
