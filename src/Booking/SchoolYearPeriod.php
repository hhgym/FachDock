<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DateTimeImmutable;
use DomainException;

final readonly class SchoolYearPeriod
{
    private function __construct(
        public int $startYear,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public string $label,
    ) {
    }

    public static function fromStartYear(int $startYear): self
    {
        if ($startYear < 2000 || $startYear > 9998) {
            throw new DomainException('Ungültiges Startjahr für das Schuljahr.');
        }

        return new self(
            $startYear,
            new DateTimeImmutable(sprintf('%04d-08-01', $startYear)),
            new DateTimeImmutable(sprintf('%04d-07-31', $startYear + 1)),
            sprintf('%04d/%02d', $startYear, ($startYear + 1) % 100),
        );
    }

    public static function containing(DateTimeImmutable $date): self
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        return self::fromStartYear($month >= 8 ? $year : $year - 1);
    }

    public function contains(DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0);

        return $day >= $this->startsOn && $day <= $this->endsOn;
    }
}
