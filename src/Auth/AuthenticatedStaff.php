<?php

declare(strict_types=1);

namespace FachDock\Auth;

final readonly class AuthenticatedStaff
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public string $email,
        public StaffRole $role,
        public int $sessionId,
    ) {
    }

    public function isAdministrator(): bool
    {
        return $this->role === StaffRole::Administrator;
    }
}
