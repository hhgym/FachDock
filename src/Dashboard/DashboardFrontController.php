<?php

declare(strict_types=1);

namespace FachDock\Dashboard;

use FachDock\Auth\StaffSessionService;
use FachDock\Booking\BookingPaymentAdminService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Payment\StripeConfigurationState;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use PDO;

final class DashboardFrontController
{
    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== '/') {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        if (is_file($root . '/storage/maintenance.flag')) {
            return Response::html(
                '<h1>FachDock wird aktualisiert.</h1><p>Bitte laden Sie die Seite in Kürze erneut.</p>',
                503,
            );
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

        $views = new ViewRenderer((string) $config->get('paths.templates', $root . '/templates'));
        $stripeState = StripeConfigurationState::fromConfig($config);

        return Response::html($views->render('home.php', [
            'appName' => (string) $config->get('app.name', 'FachDock'),
            'version' => (string) $config->get('app.version', '0.1.0-dev'),
            'schoolName' => (string) $config->get('app.school_name', ''),
            'staff' => $staff,
            'dashboard' => (new BookingPaymentAdminService($pdo))->dashboard(),
            'mailQueue' => self::mailQueueStatistics($pdo),
            'stripeMode' => $stripeState->mode,
            'stripeCheckoutAvailable' => $stripeState->checkoutAvailable(),
            'stripeProblems' => $stripeState->problems(),
            'csrfToken' => (new Csrf())->token(),
        ]));
    }

    /** @return array{waiting:int,ready:int,failed:int,processing:int,sent_today:int} */
    private static function mailQueueStatistics(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT "
            . "SUM(status = 'waiting') AS waiting, "
            . "SUM(status = 'waiting' AND available_at <= CURRENT_TIMESTAMP) AS ready, "
            . "SUM(status = 'failed') AS failed, "
            . "SUM(status = 'processing') AS processing, "
            . "SUM(status = 'sent' AND sent_at >= CURRENT_DATE) AS sent_today "
            . 'FROM mail_queue'
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['waiting' => 0, 'ready' => 0, 'failed' => 0, 'processing' => 0, 'sent_today' => 0];
        }

        return [
            'waiting' => (int) ($row['waiting'] ?? 0),
            'ready' => (int) ($row['ready'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'sent_today' => (int) ($row['sent_today'] ?? 0),
        ];
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
