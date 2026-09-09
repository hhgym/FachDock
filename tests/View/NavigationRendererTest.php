<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffRole;
use FachDock\Parent\AuthenticatedParent;
use FachDock\View\NavigationRenderer;
use PHPUnit\Framework\TestCase;

final class NavigationRendererTest extends TestCase
{
    private const CONTENT = '<html><body><header class="topbar"><div>legacy navigation</div></header><main>content</main></body></html>';

    public function testAdministratorGetsGroupedNavigationAndActiveItem(): void
    {
        $renderer = new NavigationRenderer();
        $staff = new AuthenticatedStaff(
            1,
            'admin',
            'Ada Admin',
            'ada@example.test',
            StaffRole::Administrator,
            10,
        );

        $html = $renderer->inject(self::CONTENT, [
            'staff' => $staff,
            'csrfToken' => 'csrf-admin',
        ], '/admin/config/stripe');

        self::assertStringContainsString('FachDock', $html);
        self::assertStringContainsString('Personen', $html);
        self::assertStringContainsString('Konfiguration', $html);
        self::assertStringContainsString('System', $html);
        self::assertStringContainsString('href="/admin/bookings"', $html);
        self::assertStringContainsString('href="/admin/payments"', $html);
        self::assertStringContainsString('href="/admin/system/update"', $html);
        self::assertStringContainsString('Einstellungen', $html);
        self::assertStringContainsString('href="/admin/config" aria-current="page"', $html);
        self::assertStringContainsString('E-Mail-Vorlagen &amp; Warteschlange', $html);
        self::assertStringContainsString('mobile-menu-icon', $html);
        self::assertStringContainsString('mobile-nav-section-active" open', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-admin"', $html);
        self::assertStringNotContainsString('legacy navigation', $html);
    }

    public function testLockerManagerGetsOperationalBookingNavigationButNotAdminConfiguration(): void
    {
        $renderer = new NavigationRenderer();
        $staff = new AuthenticatedStaff(
            2,
            'locker',
            'Lena Locker',
            'locker@example.test',
            StaffRole::LockerManager,
            11,
        );

        $html = $renderer->inject(self::CONTENT, [
            'staff' => $staff,
            'csrfToken' => 'csrf-locker',
        ], '/admin/payments');

        self::assertStringContainsString('href="/admin/locations"', $html);
        self::assertStringContainsString('href="/admin/bookings"', $html);
        self::assertStringContainsString('href="/admin/payments" aria-current="page"', $html);
        self::assertStringContainsString('href="/admin/but"', $html);
        self::assertStringNotContainsString('href="/admin/students"', $html);
        self::assertStringNotContainsString('href="/admin/recommendations"', $html);
        self::assertStringNotContainsString('href="/admin/config"', $html);
        self::assertStringNotContainsString('href="/admin/system/update"', $html);
    }

    public function testParentGetsPortalNavigationAndPaymentKeepsBookingActive(): void
    {
        $renderer = new NavigationRenderer();
        $parent = new AuthenticatedParent(
            3,
            'parent@example.test',
            'Erika',
            'Muster',
            20,
        );

        $html = $renderer->inject(self::CONTENT, [
            'parent' => $parent,
            'csrfToken' => 'csrf-parent',
        ], '/parent/payment/return');

        self::assertStringContainsString('Elternportal', $html);
        self::assertStringContainsString('href="/parent"', $html);
        self::assertStringContainsString('href="/parent/booking" aria-current="page"', $html);
        self::assertStringContainsString('action="/parent/logout"', $html);
        self::assertStringContainsString('mobile-menu-icon', $html);
        self::assertStringContainsString('Erika Muster', $html);
    }

    public function testUnauthenticatedContentStaysUnchanged(): void
    {
        $renderer = new NavigationRenderer();

        self::assertSame(self::CONTENT, $renderer->inject(self::CONTENT, [], '/login'));
    }
}
