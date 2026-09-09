<?php

declare(strict_types=1);

namespace FachDock\System;

use FachDock\Config\Config;
use FachDock\Payment\StripeConfigurationState;
use PDO;
use RuntimeException;

final class SystemStatusService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly string $root,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'database' => $this->database(),
            'mail_worker' => $this->mailWorker(),
            'mail_queue' => $this->mailQueue(),
            'smtp' => $this->smtp(),
            'stripe' => $this->stripe(),
            'filesystem' => $this->filesystem(),
        ];
    }

    /** @return array{ok:bool,version:string,server_time:string} */
    private function database(): array
    {
        $statement = $this->pdo->query('SELECT VERSION() AS version, CURRENT_TIMESTAMP AS server_time');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Der Datenbankstatus konnte nicht gelesen werden.');
        }

        return [
            'ok' => true,
            'version' => (string) ($row['version'] ?? ''),
            'server_time' => (string) ($row['server_time'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function mailWorker(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT worker_key, last_started_at, last_finished_at, last_success_at, last_failure_at, '
            . 'last_result_json, last_error, TIMESTAMPDIFF(MINUTE, last_success_at, CURRENT_TIMESTAMP) AS minutes_since_success '
            . 'FROM system_worker_status WHERE worker_key = :worker_key'
        );
        $statement->execute(['worker_key' => 'mail']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $warningMinutes = max(15, (int) $this->config->get('mail.processing_timeout_minutes', 15));

        if (!is_array($row)) {
            return [
                'known' => false,
                'healthy' => false,
                'stale' => true,
                'warning_minutes' => $warningMinutes,
                'last_started_at' => null,
                'last_finished_at' => null,
                'last_success_at' => null,
                'last_failure_at' => null,
                'minutes_since_success' => null,
                'last_result' => null,
                'last_error' => null,
            ];
        }

        $minutes = $row['minutes_since_success'] === null ? null : (int) $row['minutes_since_success'];
        $lastResult = null;
        if (is_string($row['last_result_json']) && $row['last_result_json'] !== '') {
            $decoded = json_decode($row['last_result_json'], true);
            if (is_array($decoded)) {
                $lastResult = $decoded;
            }
        }
        $lastSuccess = $row['last_success_at'] === null ? null : (string) $row['last_success_at'];
        $lastFailure = $row['last_failure_at'] === null ? null : (string) $row['last_failure_at'];
        $stale = $lastSuccess === null || $minutes === null || $minutes > $warningMinutes;
        $failureAfterSuccess = $lastFailure !== null && ($lastSuccess === null || $lastFailure > $lastSuccess);

        return [
            'known' => true,
            'healthy' => !$stale && !$failureAfterSuccess,
            'stale' => $stale,
            'warning_minutes' => $warningMinutes,
            'last_started_at' => $row['last_started_at'] === null ? null : (string) $row['last_started_at'],
            'last_finished_at' => $row['last_finished_at'] === null ? null : (string) $row['last_finished_at'],
            'last_success_at' => $lastSuccess,
            'last_failure_at' => $lastFailure,
            'minutes_since_success' => $minutes,
            'last_result' => $lastResult,
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
        ];
    }

    /** @return array{waiting:int,processing:int,failed:int,overdue:int,sent_last_hour:int} */
    private function mailQueue(): array
    {
        $statement = $this->pdo->query(
            "SELECT SUM(status = 'waiting') AS waiting, SUM(status = 'processing') AS processing, "
            . "SUM(status = 'failed') AS failed, "
            . "SUM(status = 'waiting' AND available_at <= CURRENT_TIMESTAMP) AS overdue "
            . 'FROM mail_queue'
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Der Status der E-Mail-Warteschlange konnte nicht gelesen werden.');
        }
        $sent = $this->pdo->query(
            "SELECT COUNT(*) FROM mail_delivery_history WHERE status = 'sent' "
            . 'AND recorded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
        );
        $sentValue = $sent === false ? false : $sent->fetchColumn();

        return [
            'waiting' => (int) ($row['waiting'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'sent_last_hour' => $sentValue === false ? 0 : (int) $sentValue,
        ];
    }

    /** @return array{configured:bool,host:string,port:int,encryption:string,from_email:string,password_configured:bool} */
    private function smtp(): array
    {
        $host = trim((string) $this->config->get('smtp.host', ''));
        $from = trim((string) $this->config->get('smtp.from_email', ''));
        $username = trim((string) $this->config->get('smtp.username', ''));
        $password = (string) $this->config->get('smtp.password', '');

        return [
            'configured' => $host !== ''
                && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
                && ($username === '' || $password !== ''),
            'host' => $host,
            'port' => (int) $this->config->get('smtp.port', 587),
            'encryption' => (string) $this->config->get('smtp.encryption', 'tls'),
            'from_email' => $from,
            'password_configured' => $password !== '',
        ];
    }

    /** @return array{mode:string,checkout_available:bool,problems:list<string>} */
    private function stripe(): array
    {
        $state = StripeConfigurationState::fromConfig($this->config);

        return [
            'mode' => $state->mode,
            'checkout_available' => $state->checkoutAvailable(),
            'problems' => $state->problems(),
        ];
    }

    /** @return list<array{name:string,path:string,writable:bool}> */
    private function filesystem(): array
    {
        $paths = [
            ['name' => 'Konfiguration', 'path' => $this->root . '/config'],
            ['name' => 'Storage', 'path' => $this->root . '/storage'],
            ['name' => 'Logs', 'path' => (string) $this->config->get('paths.logs', $this->root . '/storage/logs')],
        ];

        $result = [];
        foreach ($paths as $entry) {
            $result[] = [
                'name' => $entry['name'],
                'path' => $entry['path'],
                'writable' => is_dir($entry['path']) && is_writable($entry['path']),
            ];
        }

        return $result;
    }
}
