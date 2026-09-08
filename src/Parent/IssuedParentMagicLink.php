<?php

declare(strict_types=1);

namespace FachDock\Parent;

final readonly class IssuedParentMagicLink
{
    public function __construct(
        public string $token,
        public string $expiresAt,
    ) {
    }
}
