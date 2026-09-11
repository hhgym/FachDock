<?php

declare(strict_types=1);

namespace FachDock\Update;

use FachDock\Auth\PasswordHasher;
use FachDock\Auth\StaffSessionService;
use FachDock\Auth\StaffUserRepository;
use FachDock\Config\Config;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use RuntimeException;
use Throwable;

final class UpdateController
{
    private readonly UpdateChannel $defaultChannel;
    private readonly bool $allowReleaseCandidates;
    private readonly bool $allowDevelop;
    private readonly UpdateDiscoveryService $discovery;
    private readonly DevelopBuildState $developBuildState;

    public function __construct(
        private readonly string $currentVersion,
        GitHubReleaseClient $client,
        private readonly SelfUpdateService $updater,
        private readonly StaffUserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root);
        $this->defaultChannel = UpdateChannel::tryFrom(
            (string) $config->get('updates.default_channel', UpdateChannel::Stable->value),
        ) ?? UpdateChannel::Stable;
        $this->allowReleaseCandidates = $config->get('updates.allow_rc', false) === true;
        $this->allowDevelop = $config->get('updates.allow_develop', false) === true;
        $this->discovery = new UpdateDiscoveryService($client);
        $this->developBuildState = new DevelopBuildState($root);
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

        $channel = $this->resolveChannel($request->query()['channel'] ?? null);
        $errors = [];
        $latest = null;
        try {
            $latest = $this->discovery->latest($channel);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        return $this->page(
            $channel,
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

        $channel = $this->resolveChannel($request->postString('channel'));
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return $this->page($channel, null, ['Die Sitzung ist abgelaufen. Bitte erneut versuchen.'], null, 419);
        }

        try {
            $channel = $this->requireAllowedChannel($request->postString('channel'));
            $user = $this->users->findById($staff->id);
            if ($user === null || !$this->passwords->verify(
                $request->postString('current_password'),
                (string) $user['password_hash'],
            )) {
                throw new RuntimeException('Das aktuelle Administrator-Passwort ist nicht korrekt.');
            }

            $latest = $this->discovery->latest($channel);
            if ($request->postString('target_identity') !== $latest->identity()) {
                throw new RuntimeException('Der ausgewählte Zielstand ist nicht mehr aktuell. Bitte erneut prüfen.');
            }

            $this->updater->install($latest, $this->currentVersion, $staff->id);
            $this->csrf->rotate();

            return Response::redirect('/admin/system/update?updated=1&channel=' . rawurlencode($channel->value));
        } catch (Throwable $exception) {
            $latest = null;
            try {
                $latest = $this->discovery->latest($channel);
            } catch (Throwable) {
            }

            return $this->page($channel, $latest, [$exception->getMessage()], null, 422);
        }
    }

    /** @param list<string> $errors */
    private function page(
        UpdateChannel $channel,
        ?UpdateInfo $latest,
        array $errors,
        mixed $updated,
        int $status = 200,
    ): Response {
        return Response::html($this->views->render('update.php', [
            'staff' => $this->sessions->current(),
            'currentVersion' => $this->currentVersion,
            'installedDevelopBuild' => $this->developBuildState->current(),
            'latest' => $latest,
            'updateAvailable' => $latest !== null && $latest->isAvailableFor(
                $this->currentVersion,
                $this->developBuildState->currentBuildId(),
            ),
            'availableChannels' => $this->availableChannels(),
            'selectedChannel' => $channel,
            'csrfToken' => $this->csrf->token(),
            'errors' => $errors,
            'success' => $updated === '1',
        ]), $status);
    }

    /** @return list<UpdateChannel> */
    private function availableChannels(): array
    {
        $channels = [UpdateChannel::Stable];
        if ($this->allowReleaseCandidates) {
            $channels[] = UpdateChannel::ReleaseCandidate;
        }
        if ($this->allowDevelop) {
            $channels[] = UpdateChannel::Develop;
        }

        return $channels;
    }

    private function resolveChannel(mixed $value): UpdateChannel
    {
        $channel = is_string($value) ? UpdateChannel::tryFrom($value) : null;
        if ($channel !== null && $this->isAllowed($channel)) {
            return $channel;
        }
        if ($this->isAllowed($this->defaultChannel)) {
            return $this->defaultChannel;
        }

        return UpdateChannel::Stable;
    }

    private function requireAllowedChannel(string $value): UpdateChannel
    {
        $channel = UpdateChannel::tryFrom($value);
        if ($channel === null || !$this->isAllowed($channel)) {
            throw new RuntimeException('Der ausgewählte Update-Kanal ist auf dieser Installation nicht freigeschaltet.');
        }

        return $channel;
    }

    private function isAllowed(UpdateChannel $channel): bool
    {
        return match ($channel) {
            UpdateChannel::Stable => true,
            UpdateChannel::ReleaseCandidate => $this->allowReleaseCandidates,
            UpdateChannel::Develop => $this->allowDevelop,
        };
    }
}
