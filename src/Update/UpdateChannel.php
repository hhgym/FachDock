<?php

declare(strict_types=1);

namespace FachDock\Update;

enum UpdateChannel: string
{
    case Stable = 'stable';
    case ReleaseCandidate = 'rc';
    case Develop = 'develop';

    public function label(): string
    {
        return match ($this) {
            self::Stable => 'Stable',
            self::ReleaseCandidate => 'Release Candidate',
            self::Develop => 'Develop',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Stable => 'Nur freigegebene stabile Versionen.',
            self::ReleaseCandidate => 'Stabile Versionen und Release Candidates.',
            self::Develop => 'Der letzte erfolgreich geprüfte Build des develop-Branches.',
        };
    }
}
