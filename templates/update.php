<?php

declare(strict_types=1);

use FachDock\Update\UpdateInfo;

/** @var string $currentVersion */
/** @var UpdateInfo|null $latest */
/** @var bool $updateAvailable */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Updates · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Systemupdate</div>
    <div class="topbar-actions"><a href="/">Dashboard</a></div>
</header>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">System</span>
        <h1>FachDock aktualisieren</h1>
        <p>Installiert: Version <?= $e($currentVersion) ?></p>
    </header>

    <?php if ($success): ?>
        <div class="alert alert-success">Das Update wurde installiert und die Datenbankmigrationen wurden ausgeführt.</div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Stabiler Release-Kanal</h2>
        <?php if ($latest === null): ?>
            <p>Der aktuelle stabile GitHub-Release konnte nicht ermittelt werden.</p>
        <?php elseif (!$updateAvailable): ?>
            <p>Version <strong><?= $e($latest->version) ?></strong> ist der aktuelle stabile Release. Es ist kein Update erforderlich.</p>
        <?php else: ?>
            <p>Verfügbar: <strong>Version <?= $e($latest->version) ?></strong></p>
            <p class="form-hint">FachDock lädt das Release-ZIP und die SHA-256-Prüfsumme aus dem öffentlichen GitHub-Repository, prüft das Paket, aktiviert den Wartungsmodus, sichert überschriebene Dateien und führt anschließend ausstehende Migrationen aus.</p>
            <form method="post" action="/admin/system/update/install" class="stack">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="target_version" value="<?= $e($latest->version) ?>">
                <label>Aktuelles Administrator-Passwort
                    <input type="password" name="current_password" required autocomplete="current-password">
                    <small>Zur Bestätigung dieser sicherheitsrelevanten Aktion.</small>
                </label>
                <button class="button" type="submit">Version <?= $e($latest->version) ?> installieren</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
