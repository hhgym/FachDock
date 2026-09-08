<?php

declare(strict_types=1);

namespace FachDock\Booking;

enum AllocationRuleKind: string
{
    case HardAllow = 'hard_allow';
    case HardDeny = 'hard_deny';
    case SoftPrefer = 'soft_prefer';

    public function label(): string
    {
        return match ($this) {
            self::HardAllow => 'Verbindlich erlaubt',
            self::HardDeny => 'Verbindlich ausgeschlossen',
            self::SoftPrefer => 'Bevorzugt',
        };
    }
}
