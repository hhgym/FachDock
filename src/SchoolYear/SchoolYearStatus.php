<?php

declare(strict_types=1);

namespace FachDock\SchoolYear;

enum SchoolYearStatus: string
{
    case Future = 'future';
    case Current = 'current';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Future => 'Künftig',
            self::Current => 'Aktuell',
            self::Closed => 'Geschlossen',
        };
    }
}
