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
}
