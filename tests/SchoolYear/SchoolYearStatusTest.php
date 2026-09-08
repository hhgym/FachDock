<?php

declare(strict_types=1);

namespace FachDock\Tests\SchoolYear;

use FachDock\SchoolYear\SchoolYearStatus;
use PHPUnit\Framework\TestCase;

final class SchoolYearStatusTest extends TestCase
{
    public function testStatusValuesAndLabelsRemainStable(): void
    {
        self::assertSame('future', SchoolYearStatus::Future->value);
        self::assertSame('Künftig', SchoolYearStatus::Future->label());
        self::assertSame('current', SchoolYearStatus::Current->value);
        self::assertSame('Aktuell', SchoolYearStatus::Current->label());
        self::assertSame('closed', SchoolYearStatus::Closed->value);
        self::assertSame('Geschlossen', SchoolYearStatus::Closed->label());
    }
}
