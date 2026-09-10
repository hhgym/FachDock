<?php

declare(strict_types=1);

namespace FachDock\Mail;

use FachDock\Config\Config;
use PDO;

final readonly class MailWorkerFactory
{
    public function __construct(private Config $config)
    {
    }

    public function create(PDO $pdo, MailQueueService $queue): MailWorker
    {
        $retryValues = $this->config->get('mail.retry_minutes', [15, 60, 360]);
        $retryMinutes = [];
        if (is_array($retryValues)) {
            foreach ($retryValues as $value) {
                if (is_numeric($value)) {
                    $retryMinutes[] = (int) $value;
                }
            }
        }
        if ($retryMinutes === []) {
            $retryMinutes = [15, 60, 360];
        }

        return new MailWorker(
            $pdo,
            new PhpMailerSmtpSender(
                (string) $this->config->get('smtp.host', ''),
                $this->positiveInt('smtp.port', 587),
                (string) $this->config->get('smtp.username', ''),
                (string) $this->config->get('smtp.password', ''),
                (string) $this->config->get('smtp.encryption', 'tls'),
                (string) $this->config->get('smtp.from_email', ''),
                (string) $this->config->get('smtp.from_name', 'FachDock'),
            ),
            $queue,
            new MailRetrySchedule($retryMinutes),
            $this->positiveInt('mail.max_per_hour', 50),
            $this->positiveInt('mail.processing_timeout_minutes', 15),
            $this->nonNegativeInt('mail.immediate_reserve_per_hour', 10),
        );
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private function nonNegativeInt(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
