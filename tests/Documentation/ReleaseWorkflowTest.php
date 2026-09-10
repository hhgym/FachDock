<?php

declare(strict_types=1);

namespace FachDock\Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase
{
    public function testReleaseWorkflowSupportsRcPrereleasesWithoutChangingStableUpdatePolicy(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root . '/.github/workflows/release.yml');
        self::assertIsString($workflow);

        self::assertStringContainsString('-rc\\.[1-9][0-9]*', $workflow);
        self::assertStringContainsString('PRERELEASE=true', $workflow);
        self::assertStringContainsString('--prerelease', $workflow);
        self::assertStringContainsString('--latest=false', $workflow);
        self::assertStringContainsString('--verify-tag', $workflow);
        self::assertStringContainsString('sha256sum -c', $workflow);
        self::assertStringContainsString('unzip -t', $workflow);
        self::assertStringContainsString('test ! -e "${ROOT}/config/secrets.local.php"', $workflow);

        $client = file_get_contents($root . '/src/Update/GitHubReleaseClient.php');
        self::assertIsString($client);
        self::assertStringContainsString('/releases/latest', $client);
        self::assertStringContainsString("preg_match('/^v([0-9]+\\.[0-9]+\\.[0-9]+)$/',", $client);
        self::assertStringContainsString("(\$data['prerelease'] ?? false) === true", $client);
    }
}
