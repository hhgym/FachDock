<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class ParentLoginTemplateTest extends TestCase
{
    public function testSecondaryActionsAreStackedBelowPrivacyHint(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/parent-login.php');
        self::assertIsString($template);

        self::assertStringContainsString(
            'Aus Datenschutzgründen zeigt FachDock nicht an, ob eine eingegebene E-Mail-Adresse registriert ist.',
            $template,
        );
        self::assertStringContainsString('class="compact-actions stack"', $template);
        self::assertStringContainsString('Zum Schüler-Schließfachservice', $template);
        self::assertStringContainsString('Zur Anmeldung für Mitarbeitende', $template);
    }
}
