<?php

declare(strict_types=1);

namespace FachDock\Parent;

use DomainException;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Http\Router;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;
use Psr\Log\LoggerInterface;
use Throwable;

final class ParentPortalController
{
    public function __construct(
        private readonly ParentPortalAccessService $access,
        private readonly ParentMagicLinkService $magicLinks,
        private readonly ParentSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/parent/login', fn (Request $request): Response => $this->loginPage($request));
        $router->post('/parent/login', fn (Request $request): Response => $this->requestLogin($request));
        $router->get('/parent/magic/login', fn (Request $request): Response => $this->consumeLogin($request));
        $router->get('/parent/magic/verify', fn (Request $request): Response => $this->consumeVerification($request));
        $router->get('/parent', fn (Request $request): Response => $this->home($request));
        $router->post('/parent/logout', fn (Request $request): Response => $this->logout($request));
    }

    private function loginPage(Request $request): Response
    {
        if ($this->sessions->current() !== null) {
            return Response::redirect('/parent');
        }

        return Response::html($this->views->render('parent-login.php', [
            'csrfToken' => $this->csrf->token(),
            'submitted' => $this->queryString($request, 'sent') === '1',
            'verified' => $this->queryString($request, 'verified') === '1',
        ]));
    }

    private function requestLogin(Request $request): Response
    {
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html($this->views->render('parent-login.php', [
                'csrfToken' => $this->csrf->token(),
                'submitted' => false,
                'verified' => false,
                'error' => 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.',
            ]), 419);
        }

        try {
            $this->access->requestLogin(
                $request->postString('email'),
                $request->clientIp(),
                $request->userAgent(),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Parent Magic Link request could not be queued', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'ip_address' => $request->clientIp(),
            ]);
        }

        $this->csrf->rotate();

        return Response::redirect('/parent/login?sent=1');
    }

    private function consumeLogin(Request $request): Response
    {
        $token = $this->queryString($request, 'token');
        try {
            if ($token === '') {
                throw new DomainException('Der Magic Link ist unvollständig.');
            }
            $parentId = $this->magicLinks->consume($token, ParentMagicLinkPurpose::Login);
            $this->sessions->create($parentId, $request->clientIp(), $request->userAgent());
            $this->csrf->rotate();

            return Response::redirect('/parent');
        } catch (DomainException $exception) {
            return $this->magicLinkError($exception->getMessage());
        }
    }

    private function consumeVerification(Request $request): Response
    {
        $token = $this->queryString($request, 'token');
        try {
            if ($token === '') {
                throw new DomainException('Der Bestätigungslink ist unvollständig.');
            }
            $this->magicLinks->consume($token, ParentMagicLinkPurpose::VerifyEmail);
            $this->csrf->rotate();

            return Response::redirect('/parent/login?verified=1');
        } catch (DomainException $exception) {
            return $this->magicLinkError($exception->getMessage());
        }
    }

    private function home(Request $request): Response
    {
        unset($request);
        $parent = $this->sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        return Response::html($this->views->render('parent-home.php', [
            'parent' => $parent,
            'children' => $this->access->children($parent->id),
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    private function logout(Request $request): Response
    {
        if (!$this->csrf->verify($request->postString('_csrf'))) {
            return Response::html('<h1>Ungültige Sitzung</h1>', 419);
        }
        $parent = $this->sessions->current();
        $returnToAdministration = $parent?->adminPreview ?? false;
        $this->sessions->logout();
        $this->csrf->rotate();

        return Response::redirect($returnToAdministration ? '/admin/parents' : '/parent/login');
    }

    private function magicLinkError(string $message): Response
    {
        return Response::html($this->views->render('parent-magic-error.php', [
            'message' => $message,
        ]), 422);
    }

    private function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
