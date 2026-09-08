<?php

declare(strict_types=1);

namespace FachDock\Security;

final class Csrf
{
    private const SESSION_KEY = '_fachdock_csrf';

    public function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public function verify(string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    public function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        $this->token();
    }
}
