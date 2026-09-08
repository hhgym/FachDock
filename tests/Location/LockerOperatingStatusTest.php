<?php

declare(strict_types=1);

namespace FachDock\Tests\Location;

use FachDock\Location\LockerOperatingStatus;
use PHPUnit\Framework\TestCase;

final class LockerOperatingStatusTest extends TestCase
{
    public function testAllPersistedStatesAreStable(): void
    {
        self::assertSame('operational', LockerOperatingStatus::Operational->value);
        self::assertSame('blocked', LockerOperatingStatus::Blocked->value);
        self::assertSame('defective', LockerOperatingStatus::Defective->value);
        self::assertSame('out_of_service', LockerOperatingStatus::OutOfService->value);
    }
}
