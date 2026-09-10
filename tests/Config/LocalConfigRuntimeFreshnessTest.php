<?php

declare(strict_types=1);

namespace FachDock\Tests\Config;

use FachDock\Config\LocalConfigWriter;
use PHPUnit\Framework\TestCase;

final class LocalConfigRuntimeFreshnessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fachdock-config-fresh-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/config', 0777, true));
        $this->writeConfig('app.local.php', [
            'stripe' => ['mode' => 'test', 'currency' => 'EUR', 'checkout_minutes' => 30],
            'smtp' => [
                'host' => '',
                'port' => 587,
                'username' => '',
                'encryption' => 'tls',
                'from_email' => '',
                'from_name' => 'FachDock',
            ],
        ]);
        $this->writeConfig('secrets.local.php', [
            'stripe' => ['secret_key' => '', 'webhook_secret' => ''],
            'smtp' => ['password' => ''],
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

    public function testStripeSecretsAreReadableImmediatelyAfterSave(): void
    {
        /** @var array<string, mixed> $before */
        $before = require $this->root . '/config/secrets.local.php';
        self::assertSame('', $before['stripe']['webhook_secret']);

        (new LocalConfigWriter($this->root))->saveStripeSettings(
            'test',
            'EUR',
            30,
            'sk_test_current',
            'whsec_current',
        );

        /** @var array<string, mixed> $after */
        $after = require $this->root . '/config/secrets.local.php';
        self::assertSame('sk_test_current', $after['stripe']['secret_key']);
        self::assertSame('whsec_current', $after['stripe']['webhook_secret']);
    }

    public function testSmtpSettingsAreReadableImmediatelyAfterSave(): void
    {
        /** @var array<string, mixed> $before */
        $before = require $this->root . '/config/app.local.php';
        self::assertSame('', $before['smtp']['host']);

        (new LocalConfigWriter($this->root))->saveMailSettings(
            [
                'worker_batch_size' => 50,
                'max_per_hour' => 50,
                'retry_minutes' => [15, 60, 360],
                'processing_timeout_minutes' => 15,
            ],
            [
                'host' => 'smtp.example.test',
                'port' => 587,
                'username' => 'mailer',
                'encryption' => 'tls',
                'from_email' => 'fachdock@example.test',
                'from_name' => 'FachDock',
            ],
            'smtp-current',
        );

        /** @var array<string, mixed> $afterApp */
        $afterApp = require $this->root . '/config/app.local.php';
        /** @var array<string, mixed> $afterSecrets */
        $afterSecrets = require $this->root . '/config/secrets.local.php';

        self::assertSame('smtp.example.test', $afterApp['smtp']['host']);
        self::assertSame('fachdock@example.test', $afterApp['smtp']['from_email']);
        self::assertSame('smtp-current', $afterSecrets['smtp']['password']);
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(string $name, array $values): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        self::assertNotFalse(file_put_contents($this->root . '/config/' . $name, $content));
    }
}
