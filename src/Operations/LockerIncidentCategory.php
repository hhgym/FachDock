<?php

declare(strict_types=1);

namespace FachDock\Operations;

enum LockerIncidentCategory: string
{
    case Defect = 'defect';
    case LockProblem = 'lock_problem';
    case DoorProblem = 'door_problem';
    case Damage = 'damage';
    case CodeForgotten = 'code_forgotten';
    case EmergencyOpening = 'emergency_opening';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Defect => 'Defekt',
            self::LockProblem => 'Problem mit dem Schloss',
            self::DoorProblem => 'Problem mit der Tür',
            self::Damage => 'Beschädigung',
            self::CodeForgotten => 'Zahlencode vergessen',
            self::EmergencyOpening => 'Notöffnung erforderlich',
            self::Other => 'Sonstiges Problem',
        };
    }

    public function blocksFutureBookings(): bool
    {
        return in_array($this, [self::Defect, self::LockProblem, self::DoorProblem, self::Damage], true);
    }

    public function priority(): string
    {
        return $this === self::EmergencyOpening ? 'urgent' : 'normal';
    }
}
