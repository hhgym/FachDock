<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class BookingSelectionManagementTemplateTest extends TestCase
{
    public function testLockerManagementUsesSharedStatusModuleAndCanonicalRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $template = (string) file_get_contents($root . '/templates/locker-management.php');
        $renderer = (string) file_get_contents($root . '/src/View/LockerGridRenderer.php');
        $styles = (string) file_get_contents($root . '/public/assets/locker-grid.css');

        self::assertStringContainsString('LockerGridRenderer::statusLegend()', $template);
        self::assertStringContainsString('LockerGridRenderer::statusViews(', $template);
        self::assertStringContainsString('/admin/lockers/assign', $template);
        self::assertStringContainsString('/admin/lockers/reserve', $template);
        self::assertStringContainsString('/admin/lockers/reservation/cancel', $template);
        self::assertSame(
            1,
            substr_count($template, 'Schüler oben auswählen, um freie Fächer zuzuweisen oder zu reservieren.'),
        );

        self::assertStringContainsString('Rasteransicht', $renderer);
        self::assertStringContainsString('Listenansicht', $renderer);
        self::assertStringContainsString('locker-status-dot', $renderer);
        self::assertStringContainsString("'issue' => 'Defekt / Meldung'", $renderer);

        self::assertStringContainsString('.locker-status-dot.is-reserved', $styles);
        self::assertStringContainsString('#fff0dc', $styles);
        self::assertStringContainsString('.locker-status-dot.is-issue', $styles);
        self::assertStringContainsString('#fff9cc', $styles);
        self::assertStringContainsString('overflow-x: auto', $styles);
    }

    public function testBookingDetailReusesLockerGridRenderer(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin-booking-detail.php');

        self::assertStringContainsString('LockerGridRenderer::pickerGrid(', $template);
        self::assertStringContainsString('/assets/locker-grid.css', $template);
        self::assertStringContainsString('id="booking-locker-id"', $template);
    }
}
