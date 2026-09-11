<?php

declare(strict_types=1);

use FachDock\Update\UpdateChannel;
use FachDock\Update\UpdateInfo;

/** @var string $currentVersion */
/** @var array{build_id: string, version: string, installed_at: string}|null $installedDevelopBuild */
/** @var UpdateInfo|null $latest */
/** @var bool $updateAvailable */
/** @var list<UpdateChannel> $availableChannels */
/** @var UpdateChannel $selectedChannel */
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
        <?php if ($installedDevelopBuild !== null): ?>
            <p class="form-hint">Develop-Stand: <?= $e(substr($installedDevelopBuild['build_id'], 0, 12)) ?></p>
        <?php endif; ?>
    </header>

    <?php if ($success): ?>
        <div class="alert alert-success">Das Update wurde installiert und die Datenbankmigrationen wurden ausgeführt.</div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div>
            <h2>Update-Kanal</h2>
            <p class="form-hint">Es werden nur die in der lokalen Konfiguration freigeschalteten Kanäle angezeigt.</p>
        </div>
        <nav class="actions" aria-label="Update-Kanal auswählen">
            <?php foreach ($availableChannels as $channel): ?>
                <a
                    class="button<?= $channel === $selectedChannel ? '' : ' button-secondary' ?>"
                    href="/admin/system/update?channel=<?= $e(rawurlencode($channel->value)) ?>"
                    <?= $channel === $selectedChannel ? 'aria-current="page"' : '' ?>
                ><?= $e($channel->label()) ?></a>
            <?php endforeach; ?>
        </nav>
        <p><?= $e($selectedChannel->description()) ?></p>
        <?php if ($selectedChannel === UpdateChannel::Develop): ?>
            <div class="alert alert-warning">
                Develop enthält den letzten Build des <code>develop</code>-Branches, dessen CI vollständig erfolgreich war.
                Dieser Kanal ist ausschließlich für Tests vorgesehen.
            </div>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2><?= $e($selectedChannel->label()) ?></h2>
        <?php if ($latest === null): ?>
            <p>Der aktuelle Stand dieses Update-Kanals konnte nicht ermittelt werden.</p>
        <?php elseif (!$updateAvailable): ?>
            <p><strong><?= $e($latest->displayVersion()) ?></strong> ist bereits installiert oder es ist kein neuerer Stand verfügbar.</p>
        <?php else: ?>
            <p>Verfügbar: <strong><?= $e($latest->displayVersion()) ?></strong></p>
            <?php if ($latest->publishedAt !== null): ?>
                <p class="form-hint">Bereitgestellt: <?= $e($latest->publishedAt) ?></p>
            <?php endif; ?>
            <p class="form-hint">FachDock lädt das Update-ZIP und die SHA-256-Prüfsumme aus dem öffentlichen GitHub-Repository, prüft das Paket, aktiviert den Wartungsmodus, erstellt eine Sicherung und führt anschließend ausstehende Migrationen aus.</p>
            <form method="post" action="/admin/system/update/install" class="stack">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="channel" value="<?= $e($selectedChannel->value) ?>">
                <input type="hidden" name="target_identity" value="<?= $e($latest->identity()) ?>">
                <label>Aktuelles Administrator-Passwort
                    <input type="password" name="current_password" required autocomplete="current-password">
                    <small>Zur Bestätigung dieser sicherheitsrelevanten Aktion.</small>
                </label>
                <button class="button" type="submit"><?= $e($latest->displayVersion()) ?> installieren</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
