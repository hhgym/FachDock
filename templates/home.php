<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var string $appName */
/** @var string $version */
/** @var string $schoolName */
/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong><?= $e($appName) ?></strong><?php if ($schoolName !== ''): ?> · <?= $e($schoolName) ?><?php endif; ?></div>
    <div class="topbar-actions">
        <a href="/admin/locations">Standorte</a>
        <a href="/account/password">Passwort</a>
        <a href="/account/sessions">Sitzungen</a>
        <form method="post" action="/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="shell">
    <header class="hero">
        <span class="eyebrow"><?= $e($staff->role->label()) ?></span>
        <h1>Willkommen, <?= $e($staff->displayName) ?></h1>
        <p>Version <?= $e($version) ?></p>
    </header>
    <section class="card">
        <h2>FachDock-Grundsystem</h2>
        <p>Lokale Anmeldung, serverseitig widerrufbare Sitzungen und die Verwaltung der physischen Schließfachstruktur sind aktiv.</p>
    </section>
</main>
</body>
</html>
