<?php

declare(strict_types=1);

namespace FachDock\Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class ReadmeVersionTest extends TestCase
{
    public function testReadmeProjectStatusMatchesApplicationVersion(): void
    {
        $root = dirname(__DIR__, 2);
        /** @var array<string, mixed> $config */
        $config = require $root . '/config/app.php';
        $version = $config['app']['version'] ?? null;
        self::assertIsString($version);

        $readme = file_get_contents($root . '/README.md');
        self::assertIsString($readme);
        self::assertStringContainsString(
            'Aktuelle veröffentlichte Version: **' . $version . '**',
            $readme,
            'Beim Versions-Bump muss auch der Projektstatus in README.md aktualisiert werden.',
        );
    }
}
