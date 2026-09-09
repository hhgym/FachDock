<?php

declare(strict_types=1);

namespace FachDock\Tests\Config;

use FachDock\Config\LocalConfigWriter;
use PHPUnit\Framework\TestCase;

final class LocalConfigWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fachdock-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/config', 0777, true));
        $this->writeConfig('app.local.php', [
            'app' => ['installed' => true, 'school_name' => 'Testschule'],
            'stripe' => ['mode' => 'test', 'currency' => 'EUR', 'checkout_minutes' => 30],
        ]);
        $this->writeConfig('secrets.local.php', [
            'database' => ['password' => 'db-secret'],
            'stripe' => ['secret_key' => 'sk_test_existing', 'webhook_secret' => 'whsec_existing'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    public function testBlankSecretFieldsKeepExistingSecretsAndOtherConfiguration(): void
    {
        (new LocalConfigWriter($this->root))->saveStripeSettings('test', 'EUR', 45, null, null);

        /** @var array<string, mixed> $app */
        $app = require $this->root . '/config/app.local.php';
        /** @var array<string, mixed> $secrets */
        $secrets = require $this->root . '/config/secrets.local.php';

        self::assertSame('Testschule', $app['app']['school_name']);
        self::assertArrayNotHasKey('base_url', $app['app']);
        self::assertSame(45, $app['stripe']['checkout_minutes']);
        self::assertSame('db-secret', $secrets['database']['password']);
        self::assertSame('sk_test_existing', $secrets['stripe']['secret_key']);
        self::assertSame('whsec_existing', $secrets['stripe']['webhook_secret']);
    }

    public function testNewStripeSecretsReplaceOnlyStripeSecrets(): void
    {
        (new LocalConfigWriter($this->root))->saveStripeSettings(
            'live',
            'USD',
            60,
            'sk_live_replacement',
            'whsec_replacement',
        );

        /** @var array<string, mixed> $app */
        $app = require $this->root . '/config/app.local.php';
        /** @var array<string, mixed> $secrets */
        $secrets = require $this->root . '/config/secrets.local.php';

        self::assertSame('live', $app['stripe']['mode']);
        self::assertSame('USD', $app['stripe']['currency']);
        self::assertSame(60, $app['stripe']['checkout_minutes']);
        self::assertSame('db-secret', $secrets['database']['password']);
        self::assertSame('sk_live_replacement', $secrets['stripe']['secret_key']);
        self::assertSame('whsec_replacement', $secrets['stripe']['webhook_secret']);
    }

    public function testStripeSettingsCanPersistPublicBaseUrlWithoutOverwritingAppSettings(): void
    {
        (new LocalConfigWriter($this->root))->saveStripeSettings(
            'test',
            'EUR',
            30,
            null,
            null,
            'https://fachdock.example.de',
        );

        /** @var array<string, mixed> $app */
        $app = require $this->root . '/config/app.local.php';

        self::assertSame('Testschule', $app['app']['school_name']);
        self::assertTrue($app['app']['installed']);
        self::assertSame('https://fachdock.example.de', $app['app']['base_url']);
        self::assertSame('test', $app['stripe']['mode']);
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(string $name, array $values): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        self::assertNotFalse(file_put_contents($this->root . '/config/' . $name, $content));
    }
}
