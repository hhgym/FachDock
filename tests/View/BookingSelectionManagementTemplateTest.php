<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class BookingSelectionManagementTemplateTest extends TestCase
{
    public function testBookingSelectionOffersGroupViewsStatusesAndAdministrativeActions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/booking-selection.php');

        self::assertStringContainsString('Rasteransicht', $source);
        self::assertStringContainsString('Listenansicht', $source);
        self::assertStringContainsString('is-free', $source);
        self::assertStringContainsString('is-reserved', $source);
        self::assertStringContainsString('is-occupied', $source);
        self::assertStringContainsString('is-unavailable', $source);
        self::assertStringContainsString('/admin/booking-selection/assign', $source);
        self::assertStringContainsString('/admin/booking-selection/reserve', $source);
        self::assertStringContainsString('occupied_class_name', $source);
        self::assertStringContainsString('reserved_class_name', $source);
        self::assertStringContainsString('Direkt zuweisen', $source);
    }
}
