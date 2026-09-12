<?php

declare(strict_types=1);

use FachDock\Config\Config;
use FachDock\Identity\OidcConfiguration;

/** @var string $appName */
/** @var string $schoolName */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$oidc = new OidcConfiguration(Config::load(dirname(__DIR__)));
$oidcEnabled = $oidc->enabled();
$oidcLoginLabel = $oidc->loginLabel();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmeldung · <?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/login.css">
</head>
<body>
<main class="shell login-shell stack">
    <header class="hero">
        <span class="eyebrow"><?= $e($appName) ?></span>
        <h1>Anmeldung</h1>
        <?php if ($schoolName !== ''): ?><p><?= $e($schoolName) ?></p><?php endif; ?>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?><div><?= $e($error) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="login-primary-grid" aria-label="Zugänge für Schüler und Eltern">
        <article class="card stack login-choice login-choice-primary">
            <span class="eyebrow">Schülerinnen und Schüler</span>
            <h2>Schülerzugang</h2>
            <p>Anmeldung über den schulischen OpenID-Connect-Zugang.</p>
            <?php if ($oidcEnabled): ?>
                <a class="button" href="/sso/login?area=student"><?= $e($oidcLoginLabel) ?></a>
            <?php else: ?>
                <p class="form-hint">Der OpenID-Connect-Zugang ist derzeit nicht aktiviert.</p>
            <?php endif; ?>
        </article>

        <article class="card stack login-choice">
            <span class="eyebrow">Eltern</span>
            <h2>Elternzugang</h2>
            <p>Eltern melden sich ohne Passwort über einen einmaligen Link per E-Mail an.</p>
            <a class="button" href="/parent/login">Per E-Mail anmelden</a>
        </article>
    </section>

    <?php if ($oidcEnabled): ?>
        <section class="card login-teacher-row">
            <div>
                <strong>Lehrkräfte</strong>
                <p>Anmeldung über OpenID Connect mit lesendem Zugriff auf die Schließfachübersicht.</p>
            </div>
            <a class="button button-secondary" href="/sso/login?area=teacher"><?= $e($oidcLoginLabel) ?></a>
        </section>
    <?php endif; ?>

    <details class="card login-secondary">
        <summary>Administration und Schließfachverwaltung</summary>
        <form class="stack" method="post" action="/login" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>
                Benutzername oder E-Mail
                <input name="identifier" type="text" autocomplete="username" required>
            </label>
            <label>
                Passwort
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <button class="button" type="submit">Lokal anmelden</button>
        </form>
    </details>
</main>
</body>
</html>
