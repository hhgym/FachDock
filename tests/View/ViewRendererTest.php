<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use FachDock\View\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class ViewRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fachdock-view-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/templates', 0777, true));
        self::assertTrue(mkdir($this->root . '/public/assets', 0777, true));
        self::assertNotFalse(file_put_contents($this->root . '/public/assets/app.css', 'body { margin: 0; }'));
        self::assertNotFalse(file_put_contents($this->root . '/public/assets/navigation.css', '.topbar { display: flex; }'));
        self::assertNotFalse(file_put_contents(
            $this->root . '/templates/test.php',
            '<!doctype html><html><head><link rel="stylesheet" href="/assets/app.css"></head><body>Test</body></html>',
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/templates/test.php');
        @unlink($this->root . '/public/assets/app.css');
        @unlink($this->root . '/public/assets/navigation.css');
        @rmdir($this->root . '/templates');
        @rmdir($this->root . '/public/assets');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
    }

    public function testStylesheetsReceiveContentBasedVersions(): void
    {
        $appVersion = substr(hash_file('sha256', $this->root . '/public/assets/app.css'), 0, 12);
        $navigationVersion = substr(hash_file('sha256', $this->root . '/public/assets/navigation.css'), 0, 12);

        $html = (new ViewRenderer($this->root . '/templates'))->render('test.php');

        self::assertStringContainsString('/assets/app.css?v=' . $appVersion, $html);
        self::assertStringContainsString('/assets/navigation.css?v=' . $navigationVersion, $html);
    }
}
