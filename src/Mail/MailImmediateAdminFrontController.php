<?php

declare(strict_types=1);

namespace FachDock\Mail;

use FachDock\Audit\AuditLogger;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Security\Csrf;
use PDO;
use Throwable;

final class MailImmediateAdminFrontController
{
    private const PATH = '/admin/mail/send-now';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== self::PATH) {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        self::startSession();

        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $sessions = new StaffSessionService(
            $pdo,
            self::positiveInt($config, 'auth.session_max_lifetime_minutes', 480),
            self::positiveInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $staff = $sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        $request = Request::fromGlobals();
        if ($request->method() !== 'POST') {
            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        }
        $csrf = new Csrf();
        if (!$csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }
        $queueId = self::positiveId($request->postString('queue_id'));
        if ($queueId === null) {
            return Response::redirect('/admin/mail?send_now=invalid');
        }

        try {
            self::makeAvailableNow($pdo, $queueId);
            $queue = new MailQueueService($pdo, new MailTemplateRenderer());
            $result = (new MailWorkerFactory($config))->create($pdo, $queue)->runImmediate($queueId);
            (new AuditLogger($pdo))->staff($staff, 'mail_queue.immediate_delivery.requested', 'mail_queue', $queueId, [
                'sent' => (int) ($result['sent'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'rate_limited' => (int) ($result['rate_limited'] ?? 0),
                'deferred' => (int) ($result['deferred'] ?? 0),
            ]);
            $csrf->rotate();

            $outcome = (int) ($result['sent'] ?? 0) === 1 ? 'sent' : 'deferred';

            return Response::redirect('/admin/mail?send_now=' . $outcome);
        } catch (Throwable) {
            return Response::redirect('/admin/mail?send_now=failed');
        }
    }

    private static function makeAvailableNow(PDO $pdo, int $queueId): void
    {
        $statement = $pdo->prepare(
            "UPDATE mail_queue SET status = 'waiting', available_at = CURRENT_TIMESTAMP, "
            . 'processing_started_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE id = :id AND status IN ('waiting','failed')"
        );
        $statement->execute(['id' => $queueId]);
    }

    private static function positiveId(string $value): ?int
    {
        $value = trim($value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function positiveInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('fachdock');
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
