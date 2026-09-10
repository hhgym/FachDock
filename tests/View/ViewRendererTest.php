<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use FachDock\Parent\AuthenticatedParent;
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
        self::assertNotFalse(file_put_contents($this->root . '/public/assets/accessibility.css', '.skip-link { display: block; }'));
        self::assertNotFalse(file_put_contents($this->root . '/public/assets/app.js', 'document.documentElement.dataset.js = "1";'));
        self::assertNotFalse(file_put_contents(
            $this->root . '/templates/test.php',
            '<!doctype html><html><head><link rel="stylesheet" href="/assets/app.css"></head>'
            . '<body><header class="topbar">Legacy</header><main>Test</main></body></html>',
        ));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/templates/test.php');
        @unlink($this->root . '/public/assets/app.css');
        @unlink($this->root . '/public/assets/navigation.css');
        @unlink($this->root . '/public/assets/accessibility.css');
        @unlink($this->root . '/public/assets/app.js');
        @rmdir($this->root . '/templates');
        @rmdir($this->root . '/public/assets');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
    }

    public function testGlobalAssetsReceiveContentBasedVersionsAndAccessibilityShell(): void
    {
        $appHash = hash_file('sha256', $this->root . '/public/assets/app.css');
        $navigationHash = hash_file('sha256', $this->root . '/public/assets/navigation.css');
        $accessibilityHash = hash_file('sha256', $this->root . '/public/assets/accessibility.css');
        $scriptHash = hash_file('sha256', $this->root . '/public/assets/app.js');
        self::assertIsString($appHash);
        self::assertIsString($navigationHash);
        self::assertIsString($accessibilityHash);
        self::assertIsString($scriptHash);

        $html = (new ViewRenderer($this->root . '/templates'))->render('test.php');

        self::assertStringContainsString('/assets/app.css?v=' . substr($appHash, 0, 12), $html);
        self::assertStringContainsString('/assets/navigation.css?v=' . substr($navigationHash, 0, 12), $html);
        self::assertStringContainsString('/assets/accessibility.css?v=' . substr($accessibilityHash, 0, 12), $html);
        self::assertStringContainsString('/assets/app.js?v=' . substr($scriptHash, 0, 12), $html);
        self::assertStringContainsString('<a class="skip-link" href="#main-content">Zum Hauptinhalt</a>', $html);
        self::assertStringContainsString('<main id="main-content">', $html);
    }

    public function testAdministrativeParentPreviewIsClearlyMarkedAndCanBeEnded(): void
    {
        $parent = new AuthenticatedParent(
            4,
            'parent@example.test',
            'Erika',
            'Muster',
            0,
            true,
            9,
        );

        $html = (new ViewRenderer($this->root . '/templates'))->render('test.php', [
            'parent' => $parent,
            'csrfToken' => 'preview-csrf',
        ]);

        self::assertStringContainsString('Administrator-Testansicht', $html);
        self::assertStringContainsString('Erika Muster', $html);
        self::assertStringContainsString('keine E-Mail-Anmeldung', $html);
        self::assertStringContainsString('action="/admin/parents/preview/stop"', $html);
        self::assertStringContainsString('name="_csrf" value="preview-csrf"', $html);
        self::assertStringContainsString('Testansicht beenden', $html);
    }
}
