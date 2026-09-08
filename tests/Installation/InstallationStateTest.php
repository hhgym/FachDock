<?php

declare(strict_types=1);

namespace FachDock\Tests\Installation;

use FachDock\Installation\InstallationState;
use PHPUnit\Framework\TestCase;

final class InstallationStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fachdock-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0770, true);
    }

    protected function tearDown(): void
    {
        $file = $this->root . '/config/app.local.php';
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($this->root . '/config');
        rmdir($this->root);
    }

    public function testIsNotInstalledWithoutLocalConfiguration(): void
    {
        self::assertFalse((new InstallationState($this->root))->isInstalled());
    }

    public function testDetectsInstalledFlag(): void
    {
        file_put_contents(
            $this->root . '/config/app.local.php',
            "<?php return ['app' => ['installed' => true]];",
        );

        self::assertTrue((new InstallationState($this->root))->isInstalled());
    }
}
