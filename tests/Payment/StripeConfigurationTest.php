<?php

declare(strict_types=1);

namespace FachDock\Tests\Payment;

use DomainException;
use FachDock\Config\Config;
use FachDock\Payment\StripeConfiguration;
use PHPUnit\Framework\TestCase;

final class StripeConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fachdock-stripe-config-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['secrets.local.php', 'app.php'] as $file) {
            $path = $this->root . '/config/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->root . '/config')) {
            rmdir($this->root . '/config');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testSelectsTestCredentials(): void
    {
        $config = $this->config('test', [
            'test' => [
                'secret_key' => 'sk_test_selected',
                'webhook_secret' => 'whsec_test_selected',
            ],
            'live' => [
                'secret_key' => 'sk_live_other',
                'webhook_secret' => 'whsec_live_other',
            ],
        ]);

        $stripe = StripeConfiguration::fromConfig($config);

        self::assertSame('test', $stripe->mode);
        self::assertSame('sk_test_selected', $stripe->secretKey);
        self::assertSame('whsec_test_selected', $stripe->webhookSecret);
    }

    public function testSelectsLiveCredentials(): void
    {
        $config = $this->config('live', [
            'test' => [
                'secret_key' => 'sk_test_other',
                'webhook_secret' => 'whsec_test_other',
            ],
            'live' => [
                'secret_key' => 'sk_live_selected',
                'webhook_secret' => 'whsec_live_selected',
            ],
        ]);

        $stripe = StripeConfiguration::fromConfig($config);

        self::assertSame('live', $stripe->mode);
        self::assertSame('sk_live_selected', $stripe->secretKey);
        self::assertSame('whsec_live_selected', $stripe->webhookSecret);
    }

    public function testLegacyPairIsUsedWhenModeSpecificCredentialsAreAbsent(): void
    {
        $config = $this->config('test', [
            'secret_key' => 'sk_test_legacy',
            'webhook_secret' => 'whsec_legacy',
        ]);

        $stripe = StripeConfiguration::fromConfig($config);

        self::assertSame('sk_test_legacy', $stripe->secretKey);
        self::assertSame('whsec_legacy', $stripe->webhookSecret);
    }

    public function testPartialModeSpecificConfigurationDoesNotMixWithLegacyCredentials(): void
    {
        $config = $this->config('test', [
            'secret_key' => 'sk_test_legacy',
            'webhook_secret' => 'whsec_legacy',
            'test' => [
                'secret_key' => 'sk_test_selected',
                'webhook_secret' => '',
            ],
        ]);

        $stripe = StripeConfiguration::fromConfig($config);

        self::assertSame('sk_test_selected', $stripe->secretKey);
        self::assertSame('', $stripe->webhookSecret);
    }

    public function testUnknownModeIsRejected(): void
    {
        $config = $this->config('staging', []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('test oder live');

        StripeConfiguration::fromConfig($config);
    }

    /** @param array<string, mixed> $stripeSecrets */
    private function config(string $mode, array $stripeSecrets): Config
    {
        $this->writeConfig($this->root . '/config/app.php', [
            'stripe' => [
                'mode' => $mode,
            ],
        ]);
        $this->writeConfig($this->root . '/config/secrets.local.php', [
            'stripe' => $stripeSecrets,
        ]);

        return Config::load($this->root);
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(string $path, array $values): void
    {
        file_put_contents(
            $path,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n",
        );
    }
}
