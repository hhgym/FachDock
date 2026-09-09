<?php

declare(strict_types=1);

namespace FachDock\Operations;

enum LockerIncidentStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::InProgress => 'In Bearbeitung',
            self::Waiting => 'Wartet',
            self::Resolved => 'Erledigt',
            self::Canceled => 'Abgebrochen',
        };
    }

    public function closed(): bool
    {
        return in_array($this, [self::Resolved, self::Canceled], true);
    }
}
