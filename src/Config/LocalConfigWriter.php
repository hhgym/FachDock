<?php

declare(strict_types=1);

namespace FachDock\Config;

use RuntimeException;

final class LocalConfigWriter
{
    public function __construct(private readonly string $root)
    {
    }

    public function saveStripeSettings(
        string $mode,
        string $currency,
        int $checkoutMinutes,
        ?string $secretKey,
        ?string $webhookSecret,
    ): void {
        $appFile = $this->root . '/config/app.local.php';
        $secretsFile = $this->root . '/config/secrets.local.php';

        $app = $this->loadArray($appFile);
        $secrets = $this->loadArray($secretsFile);

        $currentStripe = isset($app['stripe']) && is_array($app['stripe']) ? $app['stripe'] : [];
        $app['stripe'] = array_replace($currentStripe, [
            'mode' => $mode,
            'currency' => $currency,
            'checkout_minutes' => $checkoutMinutes,
        ]);

        $currentSecrets = isset($secrets['stripe']) && is_array($secrets['stripe']) ? $secrets['stripe'] : [];
        if ($secretKey !== null) {
            $currentSecrets['secret_key'] = $secretKey;
        }
        if ($webhookSecret !== null) {
            $currentSecrets['webhook_secret'] = $webhookSecret;
        }
        if ($currentSecrets !== []) {
            $secrets['stripe'] = $currentSecrets;
        }

        $appTemp = $this->prepare($appFile, $app, 0640);
        $secretsTemp = $this->prepare($secretsFile, $secrets, 0600);

        try {
            $this->publish($secretsTemp, $secretsFile, 0600);
            $this->publish($appTemp, $appFile, 0640);
        } catch (RuntimeException $exception) {
            @unlink($appTemp);
            @unlink($secretsTemp);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function loadArray(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $values = require $file;
        if (!is_array($values)) {
            throw new RuntimeException('Lokale Konfigurationsdatei ist ungültig: ' . basename($file));
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function prepare(string $target, array $values, int $mode): string
    {
        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Das Konfigurationsverzeichnis ist nicht beschreibbar.');
        }

        $temp = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Lokale Konfiguration konnte nicht vorbereitet werden.');
        }
        @chmod($temp, $mode);

        return $temp;
    }

    private function publish(string $temp, string $target, int $mode): void
    {
        if (!rename($temp, $target)) {
            throw new RuntimeException('Lokale Konfiguration konnte nicht gespeichert werden.');
        }
        @chmod($target, $mode);
    }
}
