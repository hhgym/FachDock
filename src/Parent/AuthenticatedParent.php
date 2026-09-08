<?php

declare(strict_types=1);

namespace FachDock\Parent;

final readonly class AuthenticatedParent
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public int $sessionId,
    ) {
    }

    public function displayName(): string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : $this->email;
    }
}
