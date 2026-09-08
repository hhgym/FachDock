<?php

declare(strict_types=1);

namespace FachDock\Auth;

use RuntimeException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);

        if ($hash === false) {
            throw new RuntimeException('Das Passwort konnte nicht sicher gehasht werden.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_needs_rehash($hash, $algorithm);
    }
}
