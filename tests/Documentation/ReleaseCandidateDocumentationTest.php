<?php

declare(strict_types=1);

namespace FachDock\Tests\Documentation;

use FachDock\Config\Config;
use PHPUnit\Framework\TestCase;

final class ReleaseCandidateDocumentationTest extends TestCase
{
    public function testRequirementsAuditHasNoOpenOrPartialOnePointZeroBlockers(): void
    {
        $root = dirname(__DIR__, 2);
        $audit = file_get_contents($root . '/docs/REQUIREMENTS_AUDIT_1.0.md');
        self::assertIsString($audit);
        self::assertStringContainsString('FachDock 1.0 – Anforderungsabgleich', $audit);
        self::assertStringNotContainsString('| OFFEN |', $audit);
        self::assertStringNotContainsString('| TEILWEISE |', $audit);
        self::assertStringContainsString('| NACH 1.0 |', $audit);
    }

    public function testThirdReleaseCandidateIsExplicitlyVersionedAndDocumented(): void
    {
        $root = dirname(__DIR__, 2);
        $checklist = file_get_contents($root . '/docs/RELEASE_CANDIDATE.md');
        self::assertIsString($checklist);
        self::assertStringContainsString('Release-Candidate-Abnahme', $checklist);
        self::assertStringContainsString('1.0.0-rc.3', $checklist);
        self::assertStringContainsString('v0.9.0', $checklist);
        self::assertStringContainsString('Prerelease', $checklist);

        $releaseNotes = file_get_contents($root . '/docs/RELEASE_NOTES_1.0.0-rc.3.md');
        self::assertIsString($releaseNotes);
        self::assertStringContainsString('FachDock 1.0.0-rc.3', $releaseNotes);
        self::assertStringContainsString('Prerelease', $releaseNotes);
        self::assertStringContainsString('1.0.0-rc.2', $releaseNotes);

        $version = (string) Config::load($root)->get('app.version', '');
        self::assertSame('1.0.0-rc.3', $version);
        self::assertNotSame('1.0.0', $version, 'The third release candidate must not masquerade as the stable 1.0.0 release.');
    }
}
