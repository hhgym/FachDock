<?php

declare(strict_types=1);

namespace FachDock\Parent;

use DomainException;

final class ParentMagicLinkToken
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        $token = trim($token);
        if (!$this->isValidFormat($token)) {
            throw new DomainException('Der Magic-Link-Token ist ungültig.');
        }

        return hash('sha256', $token);
    }

    public function isValidFormat(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', trim($token)) === 1;
    }
}
