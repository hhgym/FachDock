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

    public function testEditableFloorplanSupportsContainedZoomResizeAndDrawPlacement(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js');
        self::assertIsString($script);

        self::assertStringContainsString('.floorplan-canvas[data-editable="1"]', $script);
        self::assertStringContainsString("editableFloorplan.style.transform = 'none';", $script);
        self::assertStringContainsString('editableFloorplan.style.width = `${zoom * 100}%`;', $script);
        self::assertStringContainsString("floorplanScroll.style.removeProperty('width');", $script);
        self::assertStringContainsString("handle.className = 'floorplan-resize-handle';", $script);
        self::assertStringContainsString("updatePlacementField(form, 'width_percent', width);", $script);
        self::assertStringContainsString("updatePlacementField(form, 'height_percent', height);", $script);
        self::assertStringContainsString("document.querySelector('form.floorplan-add-placement')", $script);
        self::assertStringContainsString("draft.className = 'floorplan-draft-rectangle';", $script);
        self::assertStringContainsString("updatePlacementField(addForm, 'x_percent', finalX);", $script);
        self::assertStringContainsString("updatePlacementField(addForm, 'width_percent', finalWidth);", $script);
        self::assertStringContainsString('addForm.requestSubmit();', $script);
    }
}
