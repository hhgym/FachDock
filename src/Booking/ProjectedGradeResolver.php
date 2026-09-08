<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DateTimeImmutable;
use DomainException;

final class ProjectedGradeResolver
{
    public function resolve(
        int $currentGrade,
        string $targetSchoolYearStartsOn,
        ?DateTimeImmutable $today = null,
    ): int {
        if ($currentGrade < 5 || $currentGrade > 12) {
            throw new DomainException('Die aktuelle Klassenstufe muss zwischen 5 und 12 liegen.');
        }

        $targetStart = DateTimeImmutable::createFromFormat('!Y-m-d', $targetSchoolYearStartsOn);
        if ($targetStart === false || $targetStart->format('Y-m-d') !== $targetSchoolYearStartsOn) {
            throw new DomainException('Das Zielschuljahr hat ein ungültiges Startdatum.');
        }

        $currentPeriod = SchoolYearPeriod::containing($today ?? new DateTimeImmutable('today'));
        $targetStartYear = (int) $targetStart->format('Y');
        $delta = $targetStartYear - $currentPeriod->startYear;
        if ($delta < 0) {
            throw new DomainException('Für ein vergangenes Schuljahr kann keine Klassenstufe prognostiziert werden.');
        }

        $projectedGrade = $currentGrade + $delta;
        if ($projectedGrade > 12) {
            throw new DomainException(
                'Für diesen Schüler ist im gewählten Schuljahr keine reguläre Klassenstufe 5 bis 12 mehr ableitbar.'
            );
        }

        return $projectedGrade;
    }
}
