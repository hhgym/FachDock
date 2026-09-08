<?php

declare(strict_types=1);

namespace FachDock\Http;

final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> */
    private array $routes = [];

    /** @param callable(Request): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method()][$request->path()] ?? null;
        if ($handler === null) {
            return Response::html('<h1>404</h1><p>Seite nicht gefunden.</p>', 404);
        }

        return $handler($request);
    }
}
