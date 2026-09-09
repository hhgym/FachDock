<?php

declare(strict_types=1);

namespace FachDock\System;

use JsonException;
use PDO;

final class WorkerHeartbeatService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $workerKey,
    ) {
    }

    public function started(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO system_worker_status (worker_key, last_started_at, updated_at) '
            . 'VALUES (:worker_key, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE last_started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['worker_key' => $this->workerKey]);
    }

    /** @param array<string, scalar|null> $result
     *  @throws JsonException
     */
    public function succeeded(array $result): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO system_worker_status '
            . '(worker_key, last_finished_at, last_success_at, last_result_json, last_error, updated_at) '
            . 'VALUES (:worker_key, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :result, NULL, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE last_finished_at = CURRENT_TIMESTAMP, last_success_at = CURRENT_TIMESTAMP, '
            . 'last_result_json = :result_update, last_error = NULL, updated_at = CURRENT_TIMESTAMP'
        );
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $statement->execute([
            'worker_key' => $this->workerKey,
            'result' => $json,
            'result_update' => $json,
        ]);
    }

    public function failed(string $error): void
    {
        $error = mb_substr(trim($error), 0, 1000);
        $statement = $this->pdo->prepare(
            'INSERT INTO system_worker_status '
            . '(worker_key, last_finished_at, last_failure_at, last_error, updated_at) '
            . 'VALUES (:worker_key, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :error, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE last_finished_at = CURRENT_TIMESTAMP, last_failure_at = CURRENT_TIMESTAMP, '
            . 'last_error = :error_update, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'worker_key' => $this->workerKey,
            'error' => $error,
            'error_update' => $error,
        ]);
    }
}
