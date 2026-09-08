<?php

declare(strict_types=1);

namespace FachDock\Tests\Http;

use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesRegisteredRoute(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request): Response => Response::html($request->path(), 201));

        $response = $router->dispatch(new Request('GET', '/health'));

        self::assertSame(201, $response->status());
        self::assertSame('/health', $response->body());
    }

    public function testReturnsNotFoundForUnknownRoute(): void
    {
        $router = new Router();
        $response = $router->dispatch(new Request('GET', '/missing'));

        self::assertSame(404, $response->status());
    }
}
