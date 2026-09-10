<?php

declare(strict_types=1);

namespace FachDock\Tests\Http;

use FachDock\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testHtmlResponsesAreNotBrowserCached(): void
    {
        $response = Response::html('<h1>Test</h1>');

        self::assertSame('no-store, private', $response->headers()['Cache-Control'] ?? null);
    }

    public function testRedirectResponsesAreNotBrowserCached(): void
    {
        $response = Response::redirect('/admin/config/stripe?saved=1');

        self::assertSame('/admin/config/stripe?saved=1', $response->headers()['Location'] ?? null);
        self::assertSame('no-store, private', $response->headers()['Cache-Control'] ?? null);
    }
}
