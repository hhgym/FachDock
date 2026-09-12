<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class ActionButtonLayoutTest extends TestCase
{
    public function testActionButtonsUseNonOverlappingFlexLayout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css');

        self::assertStringContainsString('.button { display: inline-flex;', $css);
        self::assertStringContainsString('.actions, .button-row, .cluster { display: flex; flex-wrap: wrap;', $css);
        self::assertStringContainsString('.toolbar-form { display: grid;', $css);
        self::assertStringContainsString('.grid, .recommendation-grid, .toolbar-form { grid-template-columns: 1fr; }', $css);
    }

    public function testAffectedViewsUseSharedActionLayouts(): void
    {
        $update = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/update.php');
        $accounts = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/person-accounts.php');

        self::assertStringContainsString('class="actions" aria-label="Update-Kanal auswählen"', $update);
        self::assertStringContainsString('class="button-row"', $accounts);
        self::assertStringContainsString('class="toolbar-form"', $accounts);
    }
}
