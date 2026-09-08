<?php

declare(strict_types=1);

namespace FachDock;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\AuthenticationException;
use FachDock\Auth\AuthenticationService;
use FachDock\Auth\StaffSessionService;
use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Installation\InstallationState;
use FachDock\Installation\InstallerService;
use FachDock\Installation\SystemRequirements;
use FachDock\Logging\LoggerFactory;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

final class Application
{
    private function __construct(
        private readonly string $root,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function boot(string $root): self
    {
        self::startSession();

        return new self(
            $root,
            Config::load($root),
            LoggerFactory::create($root),
        );
    }

    public function run(): void
    {
        $request = Request::fromGlobals();

        try {
            $response = $this->router()->dispatch($request);
        } catch (Throwable $exception) {
            $response = $this->errorResponse($exception);
        }

        $response->send();
    }

    private function router(): Router
    {
        $router = new Router();
        $state = new InstallationState($this->root);
        $views = new ViewRenderer((string) $this->config->get('paths.templates'));
        $csrf = new Csrf();

        $this->registerInstallerRoutes($router, $state, $views, $csrf);

        if (!$state->isInstalled()) {
            $router->get('/', static fn (Request $request): Response => Response::redirect('/install'));

            return $router;
        }

        $pdo = ConnectionFactory::fromConfig($this->config);
        $sessions = new StaffSessionService(
            $pdo,
            $this->configInt('auth.session_max_lifetime_minutes', 480),
            $this->configInt('auth.session_idle_timeout_minutes', 60),
        );
        $auth = new AuthenticationService(
            $pdo,
            $sessions,
            $this->configInt('auth.max_failed_attempts', 5),
            $this->configInt('auth.lockout_minutes', 15),
        );

        $router->get('/login', function (Request $request) use ($views, $csrf, $sessions): Response {
            unset($request);
            if ($sessions->current() !== null) {
                return Response::redirect('/');
            }

            return $this->loginPage($views, $csrf, []);
        });

        $router->post('/login', function (Request $request) use ($views, $csrf, $sessions, $auth): Response {
            if ($sessions->current() !== null) {
                return Response::redirect('/');
            }
            if (!$csrf->verify($request->postString('_csrf'))) {
                return $this->loginPage($views, $csrf, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], 419);
            }

            try {
                $auth->login(
                    $request->postString('identifier'),
                    $request->postString('password'),
                    $request->clientIp(),
                    $request->userAgent(),
                );
                $csrf->rotate();

                return Response::redirect('/');
            } catch (AuthenticationException $exception) {
                $this->logger->notice('Staff login rejected', [
                    'identifier' => $request->postString('identifier'),
                    'ip_address' => $request->clientIp(),
                ]);

                return $this->loginPage($views, $csrf, [$exception->getMessage()], 422);
            }
        });

        $router->post('/logout', function (Request $request) use ($csrf, $sessions, $auth): Response {
            if (!$csrf->verify($request->postString('_csrf'))) {
                return Response::html('<h1>Ungültige Sitzung</h1>', 419);
            }

            $auth->logout($sessions->current(), $request->clientIp());
            $csrf->rotate();

            return Response::redirect('/login');
        });

        $router->get('/', function (Request $request) use ($views, $csrf, $sessions): Response {
            unset($request);
            $staff = $sessions->current();
            if ($staff === null) {
                return Response::redirect('/login');
            }

            return Response::html($views->render('home.php', [
                'appName' => (string) $this->config->get('app.name', 'FachDock'),
                'version' => (string) $this->config->get('app.version', '0.1.0-dev'),
                'schoolName' => (string) $this->config->get('app.school_name', ''),
                'staff' => $staff,
                'csrfToken' => $csrf->token(),
            ]));
        });

        $router->get('/account/sessions', function (Request $request) use ($views, $csrf, $sessions): Response {
            unset($request);
            $staff = $sessions->current();
            if ($staff === null) {
                return Response::redirect('/login');
            }

            return $this->sessionsPage($views, $csrf, $sessions, $staff);
        });

        $router->post('/account/sessions/revoke', function (Request $request) use ($views, $csrf, $sessions): Response {
            $staff = $sessions->current();
            if ($staff === null) {
                return Response::redirect('/login');
            }
            if (!$csrf->verify($request->postString('_csrf'))) {
                return Response::html('<h1>Ungültige Sitzung</h1>', 419);
            }

            $sessionId = filter_var($request->postString('session_id'), FILTER_VALIDATE_INT);
            if ($sessionId === false || $sessionId < 1) {
                return $this->sessionsPage($views, $csrf, $sessions, $staff, ['Ungültige Sitzungs-ID.'], 422);
            }

            $sessions->revokeSession($staff->id, $sessionId);
            $csrf->rotate();

            if ($sessionId === $staff->sessionId) {
                return Response::redirect('/login');
            }

            return Response::redirect('/account/sessions');
        });

        return $router;
    }

    private function registerInstallerRoutes(
        Router $router,
        InstallationState $state,
        ViewRenderer $views,
        Csrf $csrf,
    ): void {
        $router->get('/install', function (Request $request) use ($state, $views, $csrf): Response {
            unset($request);
            if ($state->isInstalled()) {
                return Response::redirect('/');
            }

            return $this->installerPage($views, $csrf, [], []);
        });

        $router->post('/install', function (Request $request) use ($state, $views, $csrf): Response {
            if ($state->isInstalled()) {
                return Response::redirect('/');
            }

            if (!$csrf->verify($request->postString('_csrf'))) {
                return $this->installerPage(
                    $views,
                    $csrf,
                    ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'],
                    $request->post(),
                    419,
                );
            }

            try {
                (new InstallerService($this->root))->install($request->post());
                $csrf->rotate();

                return Response::redirect('/login');
            } catch (Throwable $exception) {
                $this->logger->warning('Installation failed', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return $this->installerPage($views, $csrf, [$exception->getMessage()], $request->post(), 422);
            }
        });
    }

    /** @param list<string> $errors */
    private function loginPage(ViewRenderer $views, Csrf $csrf, array $errors, int $status = 200): Response
    {
        return Response::html($views->render('login.php', [
            'appName' => (string) $this->config->get('app.name', 'FachDock'),
            'schoolName' => (string) $this->config->get('app.school_name', ''),
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    /** @param list<string> $errors */
    private function sessionsPage(
        ViewRenderer $views,
        Csrf $csrf,
        StaffSessionService $sessions,
        AuthenticatedStaff $staff,
        array $errors = [],
        int $status = 200,
    ): Response {
        return Response::html($views->render('sessions.php', [
            'staff' => $staff,
            'sessions' => $sessions->activeSessionsForUser($staff->id),
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
        ]), $status);
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $form
     */
    private function installerPage(
        ViewRenderer $views,
        Csrf $csrf,
        array $errors,
        array $form,
        int $status = 200,
    ): Response {
        unset($form['db_password'], $form['admin_password']);
        $requirements = new SystemRequirements($this->root);

        return Response::html($views->render('install.php', [
            'requirements' => $requirements->check(),
            'requirementsMet' => $requirements->allMet(),
            'csrfToken' => $csrf->token(),
            'errors' => $errors,
            'form' => $form,
        ]), $status);
    }

    private function configInt(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private function errorResponse(Throwable $exception): Response
    {
        $errorId = bin2hex(random_bytes(6));
        $this->logger->error('Unhandled application error', [
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        $debug = $this->config->get('app.debug', false) === true;
        $detail = $debug ? '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';

        return Response::html(
            '<h1>Ein Fehler ist aufgetreten.</h1><p>Fehler-ID: <code>' . $errorId . '</code></p>' . $detail,
            500,
        );
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
