<?php

declare(strict_types=1);

namespace FachDock\System;

use FachDock\Auth\StaffSessionService;
use FachDock\Http\Response;
use FachDock\Security\Csrf;
use FachDock\View\ViewRenderer;

final class SystemStatusController
{
    public function __construct(
        private readonly SystemStatusService $status,
        private readonly StaffSessionService $sessions,
        private readonly ViewRenderer $views,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(): Response
    {
        $staff = $this->sessions->current();
        if ($staff === null) {
            return Response::redirect('/login');
        }
        if (!$staff->isAdministrator()) {
            return Response::html('<h1>Zugriff verweigert</h1>', 403);
        }

        return Response::html($this->views->render('system-status.php', [
            'staff' => $staff,
            'csrfToken' => $this->csrf->token(),
            'status' => $this->status->snapshot(),
        ]));
    }
}
