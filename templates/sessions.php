<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var list<array<string, mixed>> $sessions */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktive Sitzungen · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><a href="/">FachDock</a></div>
    <div><?= $e($staff->displayName) ?></div>
</header>
<main class="shell">
    <header class="hero">
        <span class="eyebrow">Konto</span>
        <h1>Aktive Sitzungen</h1>
        <p>Du kannst einzelne Browser-Sitzungen jederzeit widerrufen.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?><div><?= $e($error) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="card stack">
        <?php foreach ($sessions as $session): ?>
            <?php $sessionId = (int) ($session['id'] ?? 0); ?>
            <div class="session-row">
                <div>
                    <strong><?= $sessionId === $staff->sessionId ? 'Diese Sitzung' : 'Weitere Sitzung' ?></strong>
                    <div class="muted"><?= $e((string) ($session['user_agent'] ?? 'Unbekannter Browser')) ?></div>
                    <div class="muted">IP: <?= $e((string) ($session['ip_address'] ?? '–')) ?> · zuletzt aktiv: <?= $e((string) ($session['last_seen_at'] ?? '')) ?></div>
                </div>
                <form method="post" action="/account/sessions/revoke">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                    <button class="button button-secondary" type="submit"><?= $sessionId === $staff->sessionId ? 'Diese Sitzung beenden' : 'Beenden' ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
