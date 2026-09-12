<?php

declare(strict_types=1);

namespace FachDock\Tests\Http;

use FachDock\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseRedirectTest extends TestCase
{
    private string $originalMethod = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    }

    protected function tearDown(): void
    {
        if ($this->originalMethod === '') {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalMethod;
        }
        parent::tearDown();
    }

    public function testDefaultRedirectAfterPostUsesSeeOther(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = Response::redirect('https://issuer.example/authorize');

        self::assertSame(303, $response->status());
        self::assertSame('https://issuer.example/authorize', $response->headers()['Location'] ?? null);
    }

    public function testDefaultRedirectAfterGetRemainsTemporaryRedirect(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = Response::redirect('/login');

        self::assertSame(302, $response->status());
    }

    public function testExplicitRedirectStatusIsPreserved(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $response = Response::redirect('/moved', 307);

        self::assertSame(307, $response->status());
    }
}
