<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class ParentBookingMapTemplateTest extends TestCase
{
    public function testCabinetGroupDialogUsesBinaryAvailabilityGrid(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/parent-booking-map.php');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/locker-grid.css');

        self::assertStringContainsString('use FachDock\\View\\LockerGridRenderer;', $template);
        self::assertStringContainsString('LockerGridRenderer::groups($group[\'lockers\'], \'id\')', $template);
        self::assertStringContainsString('locker-parent-availability-grid', $template);
        self::assertStringContainsString('locker-parent-availability-cell', $template);
        self::assertStringContainsString("'Verfügbar'", $template);
        self::assertStringContainsString("'Nicht verfügbar'", $template);
        self::assertStringContainsString('action="/parent/booking/reserve"', $template);
        self::assertStringNotContainsString('<div class="entity-list">', $template);

        self::assertStringContainsString('.locker-parent-availability-cell.is-available', $css);
        self::assertStringContainsString('background: #e8f7ed;', $css);
        self::assertStringContainsString('.locker-parent-availability-cell.is-unavailable', $css);
        self::assertStringContainsString('background: #fff0ef;', $css);
    }

    public function testBookingFlowRequiresFloorBeforePlanAndGroupSelection(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/parent-booking-map.php');

        self::assertStringContainsString('Schritt 1', $template);
        self::assertStringContainsString('Etage auswählen', $template);
        self::assertStringContainsString('<option value="" <?= $selectedFloorId === null ? \'selected\' : \'\' ?>>Bitte Etage auswählen</option>', $template);
        self::assertStringContainsString('<?php if ($selectedFloorId === null): ?>', $template);
        self::assertStringContainsString('Bitte zuerst eine Etage auswählen', $template);
        self::assertStringContainsString('Schritt 2', $template);
        self::assertStringContainsString('Schrankgruppe auswählen', $template);
        self::assertStringContainsString('Schritt 3 · Schrankgruppe', $template);
        self::assertStringContainsString('Schließfach auswählen', $template);
    }

    public function testLockerListViewKeepsHorizontalScrollContainer(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/locker-grid.css');

        self::assertStringContainsString('.locker-group-view-body.table-scroll', $css);
        self::assertStringContainsString('overflow-x: auto;', $css);
        self::assertStringContainsString('.locker-group-view-body.table-scroll > .locker-status-list', $css);
        self::assertStringContainsString('width: max-content;', $css);
    }
}
