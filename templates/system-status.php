<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var array<string, mixed> $status */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$mailWorker = is_array($status['mail_worker'] ?? null) ? $status['mail_worker'] : [];
$schoolYearWorker = is_array($status['school_year_worker'] ?? null) ? $status['school_year_worker'] : [];
$privacyWorker = is_array($status['privacy_worker'] ?? null) ? $status['privacy_worker'] : [];
$backup = is_array($status['backup'] ?? null) ? $status['backup'] : [];
$queue = is_array($status['mail_queue'] ?? null) ? $status['mail_queue'] : [];
$smtp = is_array($status['smtp'] ?? null) ? $status['smtp'] : [];
$stripe = is_array($status['stripe'] ?? null) ? $status['stripe'] : [];
$database = is_array($status['database'] ?? null) ? $status['database'] : [];
$filesystem = is_array($status['filesystem'] ?? null) ? $status['filesystem'] : [];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Systemstatus · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Systemstatus</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">System</span>
        <h1>Betriebsstatus</h1>
        <p>Überblick über Hintergrundjobs, Backup, Warteschlange, Integrationen, Datenbank und Dateisystem.</p>
    </header>

    <?php if (($mailWorker['healthy'] ?? false) !== true): ?>
        <div class="alert alert-error" role="alert">
            <strong>E-Mail-Versand prüfen.</strong>
            <?php if (($mailWorker['known'] ?? false) !== true): ?>
                Der Mail-Worker hat sich noch nie erfolgreich gemeldet.
            <?php elseif (($mailWorker['stale'] ?? false) === true): ?>
                Der letzte erfolgreiche Worker-Lauf ist älter als <?= (int) ($mailWorker['warning_minutes'] ?? 15) ?> Minuten.
            <?php else: ?>
                Der letzte Worker-Lauf ist fehlgeschlagen.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div><h2>Hintergrundjobs</h2><p class="form-hint">Die Heartbeats werden durch die jeweiligen CLI-Kommandos aktualisiert.</p></div>
        </div>
        <div class="settings-status-grid">
            <?php foreach ([
                ['Mail-Worker', 'mail:work', $mailWorker],
                ['Schuljahresjob', 'school-year:tick', $schoolYearWorker],
                ['Datenschutzjob', 'privacy:tick', $privacyWorker],
            ] as [$label, $command, $worker]): ?>
                <div class="settings-status-item">
                    <strong><?= $e((string) $label) ?></strong>
                    <span><?= ($worker['healthy'] ?? false) === true ? 'OK' : 'Prüfen' ?></span>
                    <small><code><?= $e((string) $command) ?></code></small>
                    <small>Letzter Erfolg: <?= $e((string) ($worker['last_success_at'] ?? 'noch nie')) ?></small>
                    <?php if (($worker['last_error'] ?? null) !== null): ?><small>Fehler: <?= $e((string) $worker['last_error']) ?></small><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (is_array($mailWorker['last_result'] ?? null)): ?>
            <div class="muted">Letzter Mail-Lauf: <?= (int) ($mailWorker['last_result']['processed'] ?? 0) ?> verarbeitet, <?= (int) ($mailWorker['last_result']['sent'] ?? 0) ?> gesendet, <?= (int) ($mailWorker['last_result']['deferred'] ?? 0) ?> zurückgestellt, <?= (int) ($mailWorker['last_result']['failed'] ?? 0) ?> fehlgeschlagen.</div>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div><h2>Vollbackup</h2><p class="form-hint">Empfohlen wird mindestens ein täglicher Lauf von <code>backup:create</code>.</p></div>
            <span class="badge"><?= ($backup['healthy'] ?? false) === true ? 'OK' : 'Prüfen' ?></span>
        </div>
        <div class="settings-status-grid">
            <div class="settings-status-item"><strong>Letztes Backup</strong><span><?= $e((string) ($backup['latest_file'] ?? 'noch keines')) ?></span></div>
            <div class="settings-status-item"><strong>Zeitpunkt</strong><span><?= $e((string) ($backup['latest_at'] ?? 'unbekannt')) ?></span></div>
            <div class="settings-status-item"><strong>Alter</strong><span><?= ($backup['hours_since'] ?? null) === null ? 'unbekannt' : (int) $backup['hours_since'] . ' Stunde(n)' ?></span></div>
            <div class="settings-status-item"><strong>SHA-256-Datei</strong><span><?= ($backup['checksum_present'] ?? false) === true ? 'vorhanden' : 'fehlt' ?></span></div>
        </div>
    </section>

    <section class="card stack">
        <h2>E-Mail-Warteschlange</h2>
        <div class="settings-status-grid">
            <div class="settings-status-item"><strong>Wartend</strong><span><?= (int) ($queue['waiting'] ?? 0) ?></span></div>
            <div class="settings-status-item"><strong>Versandbereit/überfällig</strong><span><?= (int) ($queue['overdue'] ?? 0) ?></span></div>
            <div class="settings-status-item"><strong>In Verarbeitung</strong><span><?= (int) ($queue['processing'] ?? 0) ?></span></div>
            <div class="settings-status-item"><strong>Fehlgeschlagen</strong><span><?= (int) ($queue['failed'] ?? 0) ?></span></div>
            <div class="settings-status-item"><strong>Letzte Stunde gesendet</strong><span><?= (int) ($queue['sent_last_hour'] ?? 0) ?></span></div>
        </div>
        <div><a class="button button-secondary" href="/admin/mail">Vorlagen &amp; Warteschlange öffnen</a></div>
    </section>

    <section class="card stack">
        <h2>Integrationen</h2>
        <div class="settings-status-grid">
            <div class="settings-status-item">
                <strong>SMTP</strong>
                <span><?= ($smtp['configured'] ?? false) === true ? 'konfiguriert' : 'unvollständig' ?></span>
                <small><?= $e((string) ($smtp['host'] ?? '')) ?><?= ($smtp['host'] ?? '') !== '' ? ':' . (int) ($smtp['port'] ?? 0) : '' ?></small>
            </div>
            <div class="settings-status-item">
                <strong>Stripe</strong>
                <span><?= ($stripe['checkout_available'] ?? false) === true ? 'Checkout verfügbar' : 'nicht vollständig' ?></span>
                <small>Modus: <?= $e((string) ($stripe['mode'] ?? 'test')) ?></small>
            </div>
        </div>
        <?php if (is_array($stripe['problems'] ?? null) && $stripe['problems'] !== []): ?>
            <div class="alert alert-neutral"><ul><?php foreach ($stripe['problems'] as $problem): ?><li><?= $e((string) $problem) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="compact-actions"><a class="button button-secondary" href="/admin/config/mail">E-Mail &amp; SMTP</a><a class="button button-secondary" href="/admin/config/stripe">Stripe &amp; Zahlung</a></div>
    </section>

    <section class="card stack">
        <h2>System</h2>
        <p><strong>Datenbank:</strong> <?= $e((string) ($database['version'] ?? 'unbekannt')) ?> · Serverzeit <?= $e((string) ($database['server_time'] ?? '')) ?></p>
        <div class="entity-list">
            <?php foreach ($filesystem as $entry): if (!is_array($entry)) { continue; } ?>
                <div class="entity-row">
                    <strong><?= $e((string) ($entry['name'] ?? 'Verzeichnis')) ?></strong>
                    <span class="code-value"><?= $e((string) ($entry['path'] ?? '')) ?></span>
                    <span><?= ($entry['writable'] ?? false) === true ? 'beschreibbar' : 'nicht beschreibbar' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
