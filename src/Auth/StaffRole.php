<?php

declare(strict_types=1);

namespace FachDock\Auth;

use InvalidArgumentException;

enum StaffRole: string
{
    case Administrator = 'administrator';
    case LockerManager = 'locker_manager';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::LockerManager => 'Schließfachverwaltung',
        };
    }

    public static function fromDatabase(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException('Unknown local staff role: ' . $value);
    }
}
