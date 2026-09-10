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

    public function testReleaseCandidateChecklistExistsAndStableVersionIsNotPrematurelyBumped(): void
    {
        $root = dirname(__DIR__, 2);
        $checklist = file_get_contents($root . '/docs/RELEASE_CANDIDATE.md');
        self::assertIsString($checklist);
        self::assertStringContainsString('Release-Candidate-Abnahme', $checklist);
        self::assertStringContainsString('Upgrade', $checklist);
        self::assertStringContainsString('v0.9.0', $checklist);

        $version = (string) Config::load($root)->get('app.version', '');
        self::assertSame('0.9.0', $version, 'Phase D must not publish the stable 1.0.0 version before RC acceptance.');
    }
}
