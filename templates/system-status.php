<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var array<string, mixed> $status */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$worker = is_array($status['mail_worker'] ?? null) ? $status['mail_worker'] : [];
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
        <p>Überblick über Mail-Worker, Warteschlange, SMTP, Stripe, Datenbank und schreibbare Systemverzeichnisse.</p>
    </header>

    <?php if (($worker['healthy'] ?? false) !== true): ?>
        <div class="alert alert-error" role="alert">
            <strong>E-Mail-Versand prüfen.</strong>
            <?php if (($worker['known'] ?? false) !== true): ?>
                Der Mail-Worker hat sich noch nie erfolgreich gemeldet.
            <?php elseif (($worker['stale'] ?? false) === true): ?>
                Der letzte erfolgreiche Worker-Lauf ist älter als <?= (int) ($worker['warning_minutes'] ?? 15) ?> Minuten.
            <?php else: ?>
                Der letzte Worker-Lauf ist fehlgeschlagen.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div><h2>Mail-Worker</h2><p class="form-hint">Der Heartbeat wird bei jedem Aufruf von <code>bin/fachdock mail:work</code> aktualisiert.</p></div>
            <span class="badge"><?= ($worker['healthy'] ?? false) === true ? 'OK' : 'Prüfen' ?></span>
        </div>
        <div class="settings-status-grid">
            <div class="settings-status-item"><strong>Letzter Erfolg</strong><span><?= $e((string) ($worker['last_success_at'] ?? 'noch nie')) ?></span></div>
            <div class="settings-status-item"><strong>Letzter Start</strong><span><?= $e((string) ($worker['last_started_at'] ?? 'noch nie')) ?></span></div>
            <div class="settings-status-item"><strong>Letzter Fehler</strong><span><?= $e((string) ($worker['last_error'] ?? 'keiner')) ?></span></div>
        </div>
        <?php if (is_array($worker['last_result'] ?? null)): ?>
            <div class="muted">Letzter Lauf: <?= (int) ($worker['last_result']['processed'] ?? 0) ?> verarbeitet, <?= (int) ($worker['last_result']['sent'] ?? 0) ?> gesendet, <?= (int) ($worker['last_result']['deferred'] ?? 0) ?> zurückgestellt, <?= (int) ($worker['last_result']['failed'] ?? 0) ?> fehlgeschlagen.</div>
        <?php endif; ?>
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
