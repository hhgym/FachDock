<?php

declare(strict_types=1);

namespace FachDock\Update;

use FachDock\Auth\PasswordHasher;
use FachDock\Auth\StaffSessionService;
use FachDock\Auth\StaffUserRepository;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Throwable;

final class UpdateController
{
    public function __construct(
        private readonly string $currentVersion,
        private readonly GitHubReleaseClient $client,
        private readonly SelfUpdateService $updater,
        private readonly StaffUserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/admin/system/update', fn (Request $request): Response => $this->index($request));
        $router->post('/admin/system/update/install', fn (Request $request): Response => $this->install($request));
    }

    private function index(Request $request): Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        $errors = [];
        $latest = null;
        try {
            $latest = $this->client->latestStable();
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        return $this->page(
            $latest,
            $errors,
            $request->query()['updated'] ?? null,
        );
    }

    private function install(Request $request): Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page(null, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], null, 419);
        }

        try {
            $user = $this->users->findById($staff->id);
            if ($user === null || !$this->passwords->verify(
                $request->postString('current_password'),
                (string) $user['password_hash'],
            )) {
                throw new \RuntimeException('Das aktuelle Administrator-Passwort ist nicht korrekt.');
            }

            $latest = $this->client->latestStable();
            if ($request->postString('target_version') !== $latest->version) {
                throw new \RuntimeException('Die ausgewählte Zielversion ist nicht mehr aktuell. Bitte erneut prüfen.');
            }

            $this->updater->install($latest, $this->currentVersion, $staff->id);
            $this->csrf->rotate();

            return Response::redirect('/admin/system/update?updated=1');
        } catch (Throwable $exception) {
            $latest = null;
            try {
                $latest = $this->client->latestStable();
            } catch (Throwable) {
            }

            return $this->page($latest, [$exception->getMessage()], null, 422);
        }
    }

    /** @param list<string> $errors */
    private function page(?UpdateInfo $latest, array $errors, mixed $updated, int $status = 200): Response
    {
        return Response::html($this->views->render('update.php', [
            'currentVersion' => $this->currentVersion,
            'latest' => $latest,
            'updateAvailable' => $latest !== null && $latest->isNewerThan($this->currentVersion),
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $updated === '1',
        ]), $status);
    }
}
