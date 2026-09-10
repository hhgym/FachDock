<?php

declare(strict_types=1);

namespace FachDock\Config;

use RuntimeException;

final class LocalConfigWriter
{
    public function __construct(private readonly string $root)
    {
    }

    public function saveGeneralSettings(string $schoolName, string $baseUrl): void
    {
        $this->save([
            'app' => [
                'school_name' => $schoolName,
                'base_url' => $baseUrl,
            ],
        ]);
    }

    /** @param array<string, int> $settings */
    public function saveAuthSettings(array $settings): void
    {
        $this->save(['auth' => $settings]);
    }

    /** @param array<string, int> $settings */
    public function saveBookingSettings(array $settings): void
    {
        $this->save(['booking' => $settings]);
    }

    /**
     * @param array{worker_batch_size:int,max_per_hour:int,immediate_reserve_per_hour:int,retry_minutes:list<int>,processing_timeout_minutes:int} $mail
     * @param array{host:string,port:int,username:string,encryption:string,from_email:string,from_name:string} $smtp
     */
    public function saveMailSettings(array $mail, array $smtp, ?string $password): void
    {
        $secrets = [];
        if ($password !== null) {
            $secrets = ['smtp' => ['password' => $password]];
        }

        $this->save([
            'mail' => $mail,
            'smtp' => $smtp,
        ], $secrets);
    }

    /** @param array<string, bool|int|string> $settings */
    public function saveOidcSettings(array $settings, ?string $clientSecret): void
    {
        $secrets = [];
        if ($clientSecret !== null) {
            $secrets = ['oidc' => ['client_secret' => $clientSecret]];
        }

        $this->save(['oidc' => $settings], $secrets);
    }

    public function saveStripeSettings(
        string $mode,
        string $currency,
        int $checkoutMinutes,
        ?string $secretKey,
        ?string $webhookSecret,
        ?string $baseUrl = null,
    ): void {
        $appChanges = [
            'stripe' => [
                'mode' => $mode,
                'currency' => $currency,
                'checkout_minutes' => $checkoutMinutes,
            ],
        ];
        if ($baseUrl !== null) {
            $appChanges['app'] = ['base_url' => $baseUrl];
        }

        $secretChanges = [];
        if ($secretKey !== null) {
            $secretChanges['stripe']['secret_key'] = $secretKey;
        }
        if ($webhookSecret !== null) {
            $secretChanges['stripe']['webhook_secret'] = $webhookSecret;
        }

        $this->save($appChanges, $secretChanges);
    }

    /**
     * @param array<string, mixed> $appChanges
     * @param array<string, mixed> $secretChanges
     */
    private function save(array $appChanges, array $secretChanges = []): void
    {
        $appFile = $this->root . '/config/app.local.php';
        $secretsFile = $this->root . '/config/secrets.local.php';

        $app = array_replace_recursive($this->loadArray($appFile), $appChanges);
        $secrets = array_replace_recursive($this->loadArray($secretsFile), $secretChanges);

        $appTemp = $this->prepare($appFile, $app, 0640);
        $secretsTemp = null;
        if ($secretChanges !== []) {
            $secretsTemp = $this->prepare($secretsFile, $secrets, 0600);
        }

        try {
            if ($secretsTemp !== null) {
                $this->publish($secretsTemp, $secretsFile, 0600);
            }
            $this->publish($appTemp, $appFile, 0640);
        } catch (RuntimeException $exception) {
            @unlink($appTemp);
            if ($secretsTemp !== null) {
                @unlink($secretsTemp);
            }
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

        clearstatcache(true, $target);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
    }
}
