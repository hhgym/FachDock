<?php

declare(strict_types=1);

namespace FachDock\Tests\Update;

use FachDock\Update\UpdateInfo;
use PHPUnit\Framework\TestCase;

final class UpdateInfoTest extends TestCase
{
    public function testDetectsNewerStableVersion(): void
    {
        $update = new UpdateInfo(
            '0.1.1',
            'v0.1.1',
            'https://github.com/hhgym/FachDock/releases/download/v0.1.1/FachDock-0.1.1.zip',
            'https://github.com/hhgym/FachDock/releases/download/v0.1.1/FachDock-0.1.1.zip.sha256',
            'https://github.com/hhgym/FachDock/releases/tag/v0.1.1',
            null,
        );

        self::assertTrue($update->isNewerThan('0.1.0'));
        self::assertFalse($update->isNewerThan('0.1.1'));
        self::assertFalse($update->isNewerThan('0.2.0'));
    }

    public function testStableOnePointZeroIsNewerThanItsReleaseCandidate(): void
    {
        $stable = new UpdateInfo(
            '1.0.0',
            'v1.0.0',
            'https://github.com/hhgym/FachDock/releases/download/v1.0.0/FachDock-1.0.0.zip',
            'https://github.com/hhgym/FachDock/releases/download/v1.0.0/FachDock-1.0.0.zip.sha256',
            'https://github.com/hhgym/FachDock/releases/tag/v1.0.0',
            null,
        );

        self::assertTrue($stable->isNewerThan('1.0.0-rc.1'));
        self::assertFalse($stable->isNewerThan('1.0.0'));
    }
}