<?php

declare(strict_types=1);

namespace FachDock\Tests\System;

use FachDock\Config\Config;
use FachDock\Migration\MigrationRunner;
use FachDock\System\SystemStatusService;
use FachDock\System\WorkerHeartbeatService;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerHeartbeatIntegrationTest extends TestCase
{
    public function testSuccessfulWorkerHeartbeatAppearsHealthyInSystemStatus(): void
    {
        $pdo = $this->database();
        (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $pdo->exec('TRUNCATE TABLE system_worker_status');
        $pdo->exec('TRUNCATE TABLE mail_delivery_history');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE mail_queue');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $heartbeat = new WorkerHeartbeatService($pdo, 'mail');
        $heartbeat->started();
        $heartbeat->succeeded([
            'processed' => 3,
            'sent' => 2,
            'deferred' => 1,
            'failed' => 0,
            'expired' => 0,
        ]);

        $status = (new SystemStatusService(
            $pdo,
            Config::load(dirname(__DIR__, 2)),
            dirname(__DIR__, 2),
        ))->snapshot();
        self::assertIsArray($status['mail_worker']);
        self::assertTrue($status['mail_worker']['known']);
        self::assertTrue($status['mail_worker']['healthy']);
        self::assertFalse($status['mail_worker']['stale']);
        self::assertSame(3, $status['mail_worker']['last_result']['processed']);
    }

    private function database(): PDO
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_worker_status';
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }
}
