<?php

declare(strict_types=1);

namespace FachDock\Tests\Location;

use FachDock\Location\CabinetGroupCode;
use PHPUnit\Framework\TestCase;

final class CabinetGroupCodeTest extends TestCase
{
    public function testSequenceUsesSpreadsheetStyleLetters(): void
    {
        self::assertSame('A', CabinetGroupCode::fromSequence(1));
        self::assertSame('Z', CabinetGroupCode::fromSequence(26));
        self::assertSame('AA', CabinetGroupCode::fromSequence(27));
        self::assertSame('AZ', CabinetGroupCode::fromSequence(52));
        self::assertSame('BA', CabinetGroupCode::fromSequence(53));
    }

    public function testNextCode(): void
    {
        self::assertSame('B', CabinetGroupCode::next('A'));
        self::assertSame('AA', CabinetGroupCode::next('Z'));
        self::assertSame('BA', CabinetGroupCode::next('AZ'));
    }
}
