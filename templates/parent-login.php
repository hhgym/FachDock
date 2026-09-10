<?php

declare(strict_types=1);

/** @var string $csrfToken */
/** @var bool $submitted */
/** @var bool $verified */
/** @var string|null $error */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$error = $error ?? null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elternzugang · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Bei FachDock anmelden</h1>
        <p>Sie erhalten einen einmal verwendbaren Anmeldelink per E-Mail.</p>
    </header>

    <?php if ($verified): ?>
        <div class="alert alert-success">Ihre E-Mail-Adresse wurde bestätigt. Sie können jetzt einen Anmeldelink anfordern.</div>
    <?php endif; ?>
    <?php if ($submitted): ?>
        <div class="alert alert-success">Falls die angegebene E-Mail-Adresse für das Elternportal freigeschaltet ist, wurde ein Anmeldelink versendet. Bitte prüfen Sie auch den Spam-Ordner.</div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="alert alert-error"><?= $e($error) ?></div>
    <?php endif; ?>

    <section class="card stack">
        <form method="post" action="/parent/login" class="stack">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>E-Mail-Adresse
                <input type="email" name="email" autocomplete="email" required maxlength="255">
            </label>
            <button class="button" type="submit">Anmeldelink anfordern</button>
        </form>
        <p class="form-hint">Aus Datenschutzgründen zeigt FachDock nicht an, ob eine eingegebene E-Mail-Adresse registriert ist.</p>
        <div class="compact-actions stack">
            <a class="button button-secondary" href="/student/support/login">Zum Schüler-Schließfachservice</a>
            <a href="/login">Zur Anmeldung für Mitarbeitende</a>
        </div>
    </section>
</main>
</body>
</html>
