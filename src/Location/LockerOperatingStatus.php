<?php

declare(strict_types=1);

namespace FachDock\Location;

enum LockerOperatingStatus: string
{
    case Operational = 'operational';
    case Blocked = 'blocked';
    case Defective = 'defective';
    case OutOfService = 'out_of_service';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Betriebsbereit',
            self::Blocked => 'Gesperrt',
            self::Defective => 'Defekt',
            self::OutOfService => 'Außer Betrieb',
        };
    }
}
