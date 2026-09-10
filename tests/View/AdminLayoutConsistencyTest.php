<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class AdminLayoutConsistencyTest extends TestCase
{
    public function testStudentImportCheckboxesUseInlineCheckLabelLayout(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/students.php');

        self::assertStringContainsString('class="wide check-label"><input type="checkbox" name="full_import"', $template);
        self::assertStringContainsString('class="check-label"><input type="checkbox" name="skip_invalid"', $template);
    }

    public function testFloorplanPageUsesStandardAdminWidthAndSingleColumnLayout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/floorplans.css');

        self::assertStringContainsString('.floorplan-page { width: min(960px, calc(100% - 32px)); margin: 48px auto 64px; }', $css);
        self::assertStringContainsString('.floorplan-layout { display: grid; grid-template-columns: 1fr;', $css);
        self::assertStringNotContainsString('min(1500px, calc(100% - 32px))', $css);
    }
}
