<?php

declare(strict_types=1);

namespace FachDock;

use FachDock\Config\Config;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Installation\InstallationState;
use FachDock\Installation\InstallerService;
use FachDock\Installation\SystemRequirements;
use FachDock\Logging\LoggerFactory;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
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

        $router->get('/', function (Request $request) use ($state, $views): Response {
            unset($request);
            if (!$state->isInstalled()) {
                return Response::redirect('/install');
            }

            return Response::html($views->render('home.php', [
                'appName' => (string) $this->config->get('app.name', 'FachDock'),
                'version' => (string) $this->config->get('app.version', '0.1.0-dev'),
                'schoolName' => (string) $this->config->get('app.school_name', ''),
            ]));
        });

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
                return $this->installerPage($views, $csrf, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], $request->post(), 419);
            }

            try {
                (new InstallerService($this->root))->install($request->post());
                $csrf->rotate();

                return Response::redirect('/');
            } catch (Throwable $exception) {
                $this->logger->warning('Installation failed', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return $this->installerPage($views, $csrf, [$exception->getMessage()], $request->post(), 422);
            }
        });

        return $router;
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

        session_name('fachdock');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
