<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DateTimeImmutable;
use DomainException;
use FachDock\Booking\ProjectedGradeResolver;
use PHPUnit\Framework\TestCase;

final class ProjectedGradeResolverTest extends TestCase
{
    public function testCurrentSchoolYearKeepsCurrentGrade(): void
    {
        $resolver = new ProjectedGradeResolver();

        self::assertSame(6, $resolver->resolve(6, '2026-08-01', new DateTimeImmutable('2026-09-08')));
    }

    public function testNextSchoolYearAdvancesGrade(): void
    {
        $resolver = new ProjectedGradeResolver();

        self::assertSame(7, $resolver->resolve(6, '2027-08-01', new DateTimeImmutable('2026-09-08')));
    }

    public function testGradeTwelveHasNoRegularNextYearProjection(): void
    {
        $resolver = new ProjectedGradeResolver();

        $this->expectException(DomainException::class);
        $resolver->resolve(12, '2027-08-01', new DateTimeImmutable('2026-09-08'));
    }

    public function testPastSchoolYearCannotBeProjected(): void
    {
        $resolver = new ProjectedGradeResolver();

        $this->expectException(DomainException::class);
        $resolver->resolve(8, '2025-08-01', new DateTimeImmutable('2026-09-08'));
    }
}
