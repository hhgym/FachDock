<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use FachDock\Config\Config;
use FachDock\Payment\StripeConfigurationState;
use PHPUnit\Framework\TestCase;

final class StripeConfigurationStateTest extends TestCase
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

    public function testTestCredentialsWithHttpsAreReady(): void
    {
        $state = $this->state('test', 'https://fachdock.example.test', 'sk_test_example', 'whsec_example');

        self::assertTrue($state->credentialsConfigured());
        self::assertTrue($state->checkoutAvailable());
        self::assertSame('test', $state->mode);
        self::assertSame([], $state->problems());
    }

    public function testLiveCredentialsWithHttpsAreReady(): void
    {
        $state = $this->state('live', 'https://fachdock.example.test', 'sk_live_example', 'whsec_example');

        self::assertTrue($state->checkoutAvailable());
        self::assertSame('live', $state->mode);
    }

    public function testSecretMustMatchConfiguredMode(): void
    {
        $state = $this->state('test', 'https://fachdock.example.test', 'sk_live_example', 'whsec_example');

        self::assertFalse($state->secretConfigured);
        self::assertFalse($state->checkoutAvailable());
        self::assertContains('Stripe Secret Key fehlt oder passt nicht zum gewählten Modus.', $state->problems());
    }

    public function testWebhookAndHttpsAreRequiredForCheckout(): void
    {
        $state = $this->state('test', 'http://fachdock.example.test', 'sk_test_example', '');

        self::assertTrue($state->secretConfigured);
        self::assertFalse($state->webhookConfigured);
        self::assertFalse($state->baseUrlSecure);
        self::assertFalse($state->checkoutAvailable());
        self::assertContains('Stripe Webhook-Secret fehlt oder ist ungültig.', $state->problems());
        self::assertContains('Die Basis-URL ist nicht als HTTPS-Adresse konfiguriert.', $state->problems());
    }

    private function state(string $mode, string $baseUrl, string $secretKey, string $webhookSecret): StripeConfigurationState
    {
        $this->root = sys_get_temp_dir() . '/fachdock-stripe-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/config', 0700, true));

        $app = [
            'app' => ['base_url' => $baseUrl],
            'stripe' => ['mode' => $mode],
        ];
        $secrets = [
            'stripe' => [
                'secret_key' => $secretKey,
                'webhook_secret' => $webhookSecret,
            ],
        ];

        file_put_contents(
            $this->root . '/config/app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($app, true) . ";\n",
        );
        file_put_contents(
            $this->root . '/config/secrets.local.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($secrets, true) . ";\n",
        );

        return StripeConfigurationState::fromConfig(Config::load($this->root));
    }
}
