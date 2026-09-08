<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var int $minimumLength */
/** @var list<string> $errors */
/** @var bool $success */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort ändern · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><a href="/">FachDock</a></div><div><?= $e($staff->displayName) ?></div></header>
<main class="shell shell-narrow">
    <header class="hero">
        <span class="eyebrow">Konto</span>
        <h1>Passwort ändern</h1>
        <p>Andere aktive Sitzungen werden nach der Änderung automatisch beendet.</p>
    </header>

    <?php if ($success): ?><div class="alert alert-success">Das Passwort wurde geändert.</div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $error): ?><div><?= $e($error) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form class="card stack" method="post" action="/account/password">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label>Aktuelles Passwort<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>Neues Passwort<input name="new_password" type="password" autocomplete="new-password" minlength="<?= $minimumLength ?>" required><small>Mindestens <?= $minimumLength ?> Zeichen.</small></label>
        <label>Neues Passwort wiederholen<input name="new_password_confirmation" type="password" autocomplete="new-password" minlength="<?= $minimumLength ?>" required></label>
        <button class="button" type="submit">Passwort ändern</button>
    </form>
</main>
</body>
</html>
