<?php

declare(strict_types=1);

namespace FachDock\Tests\Update;

use FachDock\Update\UpdateChannel;
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

    public function testDevelopBuildUsesCommitIdentity(): void
    {
        $buildId = '1234567890abcdef1234567890abcdef12345678';
        $develop = new UpdateInfo(
            '1.0.0-rc.2',
            'develop-1234567890ab',
            'https://github.com/hhgym/FachDock/raw/refs/heads/develop-build/FachDock-develop.zip',
            'https://github.com/hhgym/FachDock/raw/refs/heads/develop-build/FachDock-develop.zip.sha256',
            'https://github.com/hhgym/FachDock/commit/' . $buildId,
            null,
            UpdateChannel::Develop,
            $buildId,
        );

        self::assertTrue($develop->isAvailableFor('1.0.0-rc.2'));
        self::assertFalse($develop->isAvailableFor('1.0.0-rc.2', $buildId));
        self::assertFalse($develop->isAvailableFor('1.0.0'));
        self::assertSame($buildId, $develop->identity());
        self::assertSame('1.0.0-rc.2 · Develop 1234567890ab', $develop->displayVersion());
    }
}
