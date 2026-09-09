<?php

declare(strict_types=1);

namespace FachDock\Identity;

final class AuthenticatedOidcIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly string $issuer,
        public readonly string $subject,
        public readonly string $identityType,
        public readonly ?int $studentId,
        public readonly ?string $accountName,
        public readonly ?string $displayName,
        public readonly ?string $email,
        public readonly int $sessionId,
    ) {
    }

    public function isStudent(): bool
    {
        return $this->identityType === 'student' && $this->studentId !== null;
    }

    public function isTeacher(): bool
    {
        return $this->identityType === 'teacher';
    }

    public function label(): string
    {
        return $this->displayName ?: $this->accountName ?: $this->email ?: 'IServ-Benutzer';
    }
}
