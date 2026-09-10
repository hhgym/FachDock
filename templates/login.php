<?php

declare(strict_types=1);

/** @var string $appName */
/** @var string $schoolName */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmeldung · <?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
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

    <form class="card stack" method="post" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label>
            Benutzername oder E-Mail
            <input name="identifier" type="text" autocomplete="username" required autofocus>
        </label>
        <label>
            Passwort
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="button" type="submit">Anmelden</button>
        <p class="form-hint">Lokale Anmeldung für Administration und Schließfachverwaltung.</p>
    </form>

    <section class="card stack">
        <strong>Lehrkräfte</strong>
        <p>Lehrkräfte können sich über IServ anmelden und erhalten ausschließlich lesenden Zugriff auf die Schließfachübersicht.</p>
        <a class="button button-secondary" href="/sso/login?area=teacher">Mit IServ anmelden</a>
    </section>

    <section class="card">
        <strong>Elternzugang</strong>
        <p>Eltern melden sich ohne Passwort über einen einmaligen Link per E-Mail an.</p>
        <a href="/parent/login">Zum Elternportal</a>
    </section>
</main>
</body>
</html>
