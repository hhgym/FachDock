<?php

declare(strict_types=1);

namespace FachDock\Tests\Config;

use FachDock\Config\Config;
use PHPUnit\Framework\TestCase;

final class StripeCredentialSelectionTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root === null) {
            return;
        }
        foreach (glob($this->root . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/config');
        @rmdir($this->root);
        $this->root = null;
    }

    public function testSelectedModeUsesItsOwnCredentialPair(): void
    {
        $config = $this->config('live', [
            'test' => ['secret_key' => 'sk_test_one', 'webhook_secret' => 'whsec_test'],
            'live' => ['secret_key' => 'sk_live_one', 'webhook_secret' => 'whsec_live'],
        ]);

        self::assertSame('sk_live_one', $config->get('stripe.secret_key'));
        self::assertSame('whsec_live', $config->get('stripe.webhook_secret'));
        self::assertSame('sk_test_one', $config->get('stripe.test.secret_key'));
    }

    public function testLegacyCredentialsRemainACompatibilityFallback(): void
    {
        $config = $this->config('test', [
            'secret_key' => 'sk_test_legacy',
            'webhook_secret' => 'whsec_legacy',
        ]);

        self::assertSame('sk_test_legacy', $config->get('stripe.secret_key'));
        self::assertSame('whsec_legacy', $config->get('stripe.webhook_secret'));
    }

    public function testModeSpecificValuesAreNeverMixedWithLegacyValues(): void
    {
        $config = $this->config('test', [
            'secret_key' => 'sk_test_legacy',
            'webhook_secret' => 'whsec_legacy',
            'test' => ['secret_key' => 'sk_test_new', 'webhook_secret' => ''],
        ]);

        self::assertSame('sk_test_new', $config->get('stripe.secret_key'));
        self::assertSame('', $config->get('stripe.webhook_secret'));
    }

    /** @param array<string, mixed> $stripeSecrets */
    private function config(string $mode, array $stripeSecrets): Config
    {
        $this->root = sys_get_temp_dir() . '/fachdock-stripe-selection-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/config', 0700, true));

        $app = [
            'app' => ['base_url' => 'https://fachdock.example.test'],
            'stripe' => ['mode' => $mode],
        ];
        $secrets = ['stripe' => $stripeSecrets];

        file_put_contents(
            $this->root . '/config/app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($app, true) . ";\n",
        );
        file_put_contents(
            $this->root . '/config/secrets.local.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($secrets, true) . ";\n",
        );

        return Config::load($this->root);
    }
}
