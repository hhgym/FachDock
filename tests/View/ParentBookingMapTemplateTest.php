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
}
