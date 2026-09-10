<?php

declare(strict_types=1);

namespace FachDock\Tests\Mail;

use FachDock\Mail\MailMessage;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailSender;
use FachDock\Mail\MailTemplateRenderer;
use FachDock\Mail\MailWorker;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailWorkerImmediateIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MailQueueService $queue;

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('TRUNCATE TABLE mail_delivery_history');
        $this->pdo->exec('TRUNCATE TABLE mail_queue');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->queue = new MailQueueService($this->pdo, new MailTemplateRenderer());
    }

    public function testImmediateMailUsesAndCountsAgainstGlobalHourlyCapacity(): void
    {
        $sender = new class () implements MailSender {
            /** @var list<MailMessage> */
            public array $messages = [];

            public function send(MailMessage $message): void
            {
                $this->messages[] = $message;
            }
        };
        $worker = new MailWorker($this->pdo, $sender, $this->queue, maxPerHour: 2, immediateReservePerHour: 1);

        $immediateId = $this->enqueue(MailWorker::IMMEDIATE_PRIORITY_MAX, 'immediate');
        $immediate = $worker->runImmediate($immediateId);
        self::assertSame(1, $immediate['sent']);

        $firstStandard = $this->enqueue(100, 'standard-1');
        $secondStandard = $this->enqueue(100, 'standard-2');
        $regular = $worker->run(10);

        self::assertSame(1, $regular['sent']);
        self::assertSame('sent', $this->queueStatus($firstStandard));
        self::assertSame('waiting', $this->queueStatus($secondStandard));
        self::assertCount(2, $sender->messages);
        self::assertSame(2, $this->sentLastHour());
    }

    public function testRegularQueueLeavesConfiguredCapacityForImmediateMail(): void
    {
        $sender = new class () implements MailSender {
            /** @var list<MailMessage> */
            public array $messages = [];

            public function send(MailMessage $message): void
            {
                $this->messages[] = $message;
            }
        };
        $worker = new MailWorker($this->pdo, $sender, $this->queue, maxPerHour: 3, immediateReservePerHour: 1);

        $first = $this->enqueue(100, 'bulk-1');
        $second = $this->enqueue(100, 'bulk-2');
        $third = $this->enqueue(100, 'bulk-3');
        $regular = $worker->run(10);

        self::assertSame(2, $regular['sent']);
        self::assertSame('sent', $this->queueStatus($first));
        self::assertSame('sent', $this->queueStatus($second));
        self::assertSame('waiting', $this->queueStatus($third));

        $immediateId = $this->enqueue(MailWorker::IMMEDIATE_PRIORITY_MAX, 'magic-link');
        $immediate = $worker->runImmediate($immediateId);

        self::assertSame(1, $immediate['sent']);
        self::assertSame('sent', $this->queueStatus($immediateId));
        self::assertSame(3, $this->sentLastHour());
        self::assertCount(3, $sender->messages);
    }

    public function testImmediateMailRemainsWaitingWhenGlobalLimitIsExhausted(): void
    {
        $sender = new class () implements MailSender {
            public function send(MailMessage $message): void
            {
                unset($message);
            }
        };
        $worker = new MailWorker($this->pdo, $sender, $this->queue, maxPerHour: 1, immediateReservePerHour: 0);

        $standardId = $this->enqueue(100, 'fills-limit');
        self::assertSame(1, $worker->run(1)['sent']);
        self::assertSame('sent', $this->queueStatus($standardId));

        $immediateId = $this->enqueue(MailWorker::IMMEDIATE_PRIORITY_MAX, 'rate-limited');
        $result = $worker->runImmediate($immediateId);

        self::assertSame(1, $result['rate_limited']);
        self::assertSame(0, $result['processed']);
        self::assertSame('waiting', $this->queueStatus($immediateId));
    }

    private function enqueue(int $priority, string $suffix): int
    {
        return $this->queue->enqueue(
            'parent_login',
            $suffix . '@example.test',
            null,
            [
                'school_name' => 'Testschule',
                'parent_name_suffix' => '',
                'expires_minutes' => 15,
            ],
            ['magic_link' => 'https://example.test/magic/' . $suffix],
            priority: $priority,
            deduplicationKey: 'mail-worker-immediate-' . $suffix,
        );
    }

    private function queueStatus(int $queueId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM mail_queue WHERE id = :id');
        $statement->execute(['id' => $queueId]);

        return (string) $statement->fetchColumn();
    }

    private function sentLastHour(): int
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM mail_delivery_history WHERE status = 'sent' "
            . 'AND recorded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
        );

        return (int) $statement->fetchColumn();
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
        $database = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test') . '_mail_immediate';
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
