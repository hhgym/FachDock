<?php

declare(strict_types=1);

namespace FachDock\Tests\Location;

use FachDock\Location\LockerNaming;
use PHPUnit\Framework\TestCase;

final class LockerNamingTest extends TestCase
{
    public function testShortName(): void
    {
        self::assertSame('A-07-2', LockerNaming::shortName('A', 7, 2));
        self::assertSame('AA-12-4', LockerNaming::shortName('AA', 12, 4));
    }

    public function testLongName(): void
    {
        self::assertSame('1OG-78-A-07-2', LockerNaming::longName('1OG', '78', 'A', 7, 2));
    }
}
