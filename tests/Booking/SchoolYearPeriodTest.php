<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DateTimeImmutable;
use FachDock\Booking\SchoolYearPeriod;
use PHPUnit\Framework\TestCase;

final class SchoolYearPeriodTest extends TestCase
{
    public function testSchoolYearRunsFromAugustToJuly(): void
    {
        $period = SchoolYearPeriod::fromStartYear(2026);

        self::assertSame('2026/27', $period->label);
        self::assertSame('2026-08-01', $period->startsOn->format('Y-m-d'));
        self::assertSame('2027-07-31', $period->endsOn->format('Y-m-d'));
        self::assertTrue($period->contains(new DateTimeImmutable('2027-03-15')));
        self::assertFalse($period->contains(new DateTimeImmutable('2027-08-01')));
    }

    public function testContainingResolvesBothCalendarHalves(): void
    {
        self::assertSame('2026/27', SchoolYearPeriod::containing(new DateTimeImmutable('2026-09-01'))->label);
        self::assertSame('2026/27', SchoolYearPeriod::containing(new DateTimeImmutable('2027-02-01'))->label);
    }
}
