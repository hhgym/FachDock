<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DateTimeImmutable;
use DomainException;
use FachDock\Booking\FeeCalculator;
use FachDock\Booking\SchoolYearPeriod;
use PHPUnit\Framework\TestCase;

final class FeeCalculatorTest extends TestCase
{
    public function testAugustChargesFullAnnualFee(): void
    {
        $result = (new FeeCalculator())->prorate(
            12000,
            new DateTimeImmutable('2026-08-20'),
            SchoolYearPeriod::fromStartYear(2026),
        );

        self::assertSame(['months' => 12, 'charged_cents' => 12000], $result);
    }

    public function testMarchIncludesBookingMonthThroughJuly(): void
    {
        $result = (new FeeCalculator())->prorate(
            12000,
            new DateTimeImmutable('2027-03-20'),
            SchoolYearPeriod::fromStartYear(2026),
        );

        self::assertSame(['months' => 5, 'charged_cents' => 5000], $result);
    }

    public function testDateOutsideSchoolYearIsRejected(): void
    {
        $this->expectException(DomainException::class);

        (new FeeCalculator())->prorate(
            12000,
            new DateTimeImmutable('2027-08-01'),
            SchoolYearPeriod::fromStartYear(2026),
        );
    }
}
