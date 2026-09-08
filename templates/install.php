<?php

declare(strict_types=1);

/** @var list<array{key: string, label: string, ok: bool, detail: string}> $requirements */
/** @var bool $requirementsMet */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var array<string, mixed> $form */

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = static function (string $key, string $default = '') use ($form, $e): string {
    $current = $form[$key] ?? $default;

    return is_scalar($current) ? $e((string) $current) : $e($default);
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FachDock installieren</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell">
    <header class="hero">
        <span class="eyebrow">FachDock</span>
        <h1>Installation</h1>
        <p>Richten Sie Datenbank, Schule und das erste Administratorkonto ein.</p>
    </header>

    <?php if ($errors !== []): ?>
        <section class="alert alert-error" aria-live="polite">
            <strong>Installation nicht abgeschlossen</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Systemvoraussetzungen</h2>
        <div class="requirements">
            <?php foreach ($requirements as $requirement): ?>
                <div class="requirement <?= $requirement['ok'] ? 'ok' : 'fail' ?>">
                    <span><?= $requirement['ok'] ? '✓' : '!' ?></span>
                    <div>
                        <strong><?= $e($requirement['label']) ?></strong>
                        <small><?= $e($requirement['detail']) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <form method="post" action="/install" class="stack">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

        <section class="card">
            <h2>Datenbank</h2>
            <div class="grid">
                <label>Host<input name="db_host" required value="<?= $value('db_host', '127.0.0.1') ?>"></label>
                <label>Port<input name="db_port" inputmode="numeric" required value="<?= $value('db_port', '3306') ?>"></label>
                <label>Datenbank<input name="db_name" required value="<?= $value('db_name', 'fachdock') ?>"></label>
                <label>Benutzer<input name="db_username" required value="<?= $value('db_username', 'fachdock') ?>"></label>
                <label class="wide">Passwort<input type="password" name="db_password" autocomplete="new-password"></label>
            </div>
        </section>

        <section class="card">
            <h2>Schule</h2>
            <label>Schulname<input name="school_name" required value="<?= $value('school_name') ?>"></label>
        </section>

        <section class="card">
            <h2>Erstes Administratorkonto</h2>
            <div class="grid">
                <label>Benutzername<input name="admin_username" required autocomplete="username" value="<?= $value('admin_username', 'admin') ?>"></label>
                <label>Anzeigename<input name="admin_display_name" required value="<?= $value('admin_display_name') ?>"></label>
                <label class="wide">E-Mail<input type="email" name="admin_email" required value="<?= $value('admin_email') ?>"></label>
                <label class="wide">Passwort<input type="password" name="admin_password" minlength="12" required autocomplete="new-password"><small>Mindestens 12 Zeichen.</small></label>
            </div>
        </section>

        <button class="button" type="submit" <?= $requirementsMet ? '' : 'disabled' ?>>FachDock installieren</button>
    </form>
</main>
</body>
</html>
