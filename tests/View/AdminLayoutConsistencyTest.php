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

    public function testAllDirectCheckboxLabelsStayInlineGlobally(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/accessibility.css');

        self::assertStringContainsString('label:has(> input[type="checkbox"]) {', $css);
        self::assertStringContainsString('display: flex;', $css);
        self::assertStringContainsString('align-items: center;', $css);
        self::assertStringContainsString('label:has(> input[type="checkbox"]) > input[type="checkbox"] {', $css);
    }

    public function testFloorplanPageUsesStandardAdminWidthAndSingleColumnLayout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/floorplans.css');

        self::assertStringContainsString('.floorplan-page { width: min(960px, calc(100% - 32px)); margin: 48px auto 64px; }', $css);
        self::assertStringContainsString('.floorplan-layout { display: grid; grid-template-columns: 1fr;', $css);
        self::assertStringNotContainsString('min(1500px, calc(100% - 32px))', $css);
    }

    public function testDashboardActionsStackOnNarrowScreens(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/dashboard.css');

        self::assertStringContainsString('.compact-actions {', $css);
        self::assertStringContainsString('flex-wrap: wrap;', $css);
        self::assertStringContainsString('@media (max-width: 680px)', $css);
        self::assertStringContainsString('grid-template-columns: 1fr;', $css);
        self::assertStringContainsString('width: 100%;', $css);
    }

    public function testDashboardIncludesAnimatedCountersAndAccessibleProgressComparison(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/home.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/dashboard.js');

        self::assertStringContainsString('data-dashboard-counter=', $template);
        self::assertStringContainsString('data-dashboard-progress=', $template);
        self::assertStringContainsString('<progress class="dashboard-progress"', $template);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $script);
    }

    public function testOperationsFiltersStackWithoutOverlapOnNarrowScreens(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/operations-admin.php');

        self::assertStringContainsString('<div class="compact-actions">', $template);
        self::assertStringContainsString('.school-year-heading > .compact-actions { display: flex; flex-wrap: wrap;', $css);
        self::assertStringContainsString('.school-year-heading > .compact-actions { width: 100%; flex-direction: column; align-items: stretch; }', $css);
        self::assertStringContainsString('.school-year-heading > .compact-actions .button { width: 100%; text-align: center; }', $css);
    }

    public function testOperationsBookableCheckboxesUseInlineCheckLabelLayout(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/operations-admin.php');

        self::assertSame(2, substr_count($template, 'class="check-label"><input type="checkbox" name="bookable"'));
        self::assertSame(2, substr_count($template, '<span>Für neue Buchungen freigeben</span>'));
    }

    public function testFloorplanEditorContainsZoomAndResizeInsideTheCard(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/floorplans.css');

        self::assertStringContainsString('.floorplan-scroll { width: 100% !important; max-width: 100%;', $css);
        self::assertStringContainsString('overflow: auto;', $css);
        self::assertStringContainsString('.floorplan-resize-handle {', $css);
        self::assertStringContainsString('.floorplan-canvas.floorplan-drawing { cursor: crosshair; }', $css);
        self::assertStringContainsString('.floorplan-draft-rectangle {', $css);
    }
}
