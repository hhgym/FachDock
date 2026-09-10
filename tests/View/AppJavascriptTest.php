<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class AppJavascriptTest extends TestCase
{
    public function testDesktopHeaderMenusCloseAfterMouseLeavesMenuArea(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js');
        self::assertIsString($script);

        self::assertStringContainsString(
            "document.querySelectorAll('details.nav-menu, details.account-menu')",
            $script,
        );
        self::assertStringContainsString("menu.addEventListener('pointerleave'", $script);
        self::assertStringContainsString("event.pointerType !== 'mouse'", $script);
        self::assertStringContainsString('window.setTimeout(() => {', $script);
        self::assertStringContainsString('menu.open = false;', $script);
        self::assertStringContainsString('}, 120);', $script);
        self::assertStringContainsString("menu.addEventListener('pointerenter'", $script);
        self::assertStringContainsString('cancelClose();', $script);
    }
}
