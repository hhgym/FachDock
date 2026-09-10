<?php

declare(strict_types=1);
use FachDock\Auth\AuthenticatedStaff;
/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var bool $testSuccess */
/** @var int $workerBatchSize */
/** @var int $maxPerHour */
/** @var int $immediateReservePerHour */
/** @var string $retryMinutes */
/** @var int $processingTimeoutMinutes */
/** @var string $smtpHost */
/** @var int $smtpPort */
/** @var string $smtpUsername */
/** @var string $smtpEncryption */
/** @var string $smtpFromEmail */
/** @var string $smtpFromName */
/** @var bool $smtpPasswordConfigured */
/** @var bool $smtpConfigured */
/** @var array{known:bool,last_started_at:?string,last_finished_at:?string,last_success_at:?string,last_failure_at:?string,minutes_since_success:?int,last_error:?string} $mailWorkerStatus */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$lastSuccess = $mailWorkerStatus['last_success_at'];
$lastFailure = $mailWorkerStatus['last_failure_at'];
$failureAfterSuccess = $lastFailure !== null && ($lastSuccess === null || $lastFailure > $lastSuccess);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>E-Mail & SMTP · FachDock</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack"><header class="hero"><span class="eyebrow">Konfiguration</span><h1>E-Mail & SMTP</h1><p>Transportkonfiguration und technische Parameter der Versandwarteschlange.</p></header>
<p><a href="/admin/config">← Zur Konfigurationsübersicht</a> · <a href="/admin/mail">Vorlagen & Warteschlange öffnen</a></p>
<?php if ($success): ?><div class="alert alert-success">Die E-Mail-Konfiguration wurde gespeichert.</div><?php endif; ?>
<?php if ($testSuccess): ?><div class="alert alert-success">Die Test-E-Mail wurde vom SMTP-Server angenommen.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="card stack">
<div class="school-year-heading"><div><h2>Status</h2><p class="form-hint">Das SMTP-Passwort wird niemals angezeigt.</p></div><span class="badge"><?= $smtpConfigured ? 'SMTP konfiguriert' : 'SMTP unvollständig' ?></span></div>
<div class="alert alert-neutral"><strong>Der Mail-Worker muss extern gestartet werden.</strong> Für den regulären Betrieb <code>php bin/fachdock mail:work</code> per Cronjob oder systemd-Timer ausführen, empfohlen einmal pro Minute. Die Webanwendung startet den periodischen Worker nicht selbst.</div>
<?php if ($lastSuccess !== null): ?>
    <p><strong>Letzter erfolgreicher Mail-Worker-Lauf:</strong> <?= $e($lastSuccess) ?><?php if ($mailWorkerStatus['minutes_since_success'] !== null): ?> · vor <?= (int) $mailWorkerStatus['minutes_since_success'] ?> Minute(n)<?php endif; ?></p>
<?php elseif ($mailWorkerStatus['known']): ?>
    <p><strong>Mail-Worker:</strong> Es wurde noch kein erfolgreicher Lauf registriert.</p>
<?php else: ?>
    <p><strong>Mail-Worker:</strong> Noch kein Lauf registriert. Nach Einrichtung des Cronjobs sollte hier spätestens nach dem ersten erfolgreichen Aufruf ein Zeitpunkt erscheinen.</p>
<?php endif; ?>
<?php if ($failureAfterSuccess): ?>
    <div class="alert alert-error"><strong>Der letzte Mail-Worker-Lauf ist fehlgeschlagen.</strong><?php if ($mailWorkerStatus['last_error'] !== null): ?> <?= $e($mailWorkerStatus['last_error']) ?><?php endif; ?></div>
<?php endif; ?>
<details><summary><strong>Beispiel für Cron (jede Minute)</strong></summary><pre><code>* * * * * cd /pfad/zu/fachdock &amp;&amp; /usr/bin/php bin/fachdock mail:work &gt;/dev/null 2&gt;&amp;1</code></pre></details>
</section>
<form class="card stack" method="post" action="/admin/config/mail" autocomplete="off"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<h2>SMTP</h2><div class="grid">
<label>SMTP-Host<input name="smtp_host" value="<?= $e($smtpHost) ?>" placeholder="smtp.example.de"><small>Host und Absenderadresse leer lassen, um SMTP bewusst zu deaktivieren.</small></label>
<label>Port<input type="number" name="smtp_port" min="1" max="65535" value="<?= $smtpPort ?>" required></label>
<label>Benutzername<input name="smtp_username" value="<?= $e($smtpUsername) ?>" autocomplete="off"><small>Leer lassen für einen SMTP-Server ohne Authentifizierung.</small></label>
<label>Passwort<input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= $smtpPasswordConfigured ? 'gespeichertes Passwort beibehalten' : 'Passwort' ?>"><small>Leer lassen, um das gespeicherte Passwort unverändert zu lassen.</small></label>
<label>Verschlüsselung<select name="smtp_encryption"><option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>STARTTLS / TLS</option><option value="smtps" <?= in_array($smtpEncryption, ['smtps', 'ssl'], true) ? 'selected' : '' ?>>SMTPS / SSL</option><option value="none" <?= in_array($smtpEncryption, ['', 'none'], true) ? 'selected' : '' ?>>Keine</option></select></label>
<label>Absenderadresse<input type="email" name="smtp_from_email" value="<?= $e($smtpFromEmail) ?>" placeholder="fachdock@example.de"></label>
<label>Absendername<input name="smtp_from_name" maxlength="160" value="<?= $e($smtpFromName) ?>" required></label>
</div>
<h2>Versandwarteschlange</h2>
<div class="alert alert-neutral"><strong>Sofortmails:</strong> Zeitkritische Nachrichten wie Eltern-Magic-Links und E-Mail-Bestätigungslinks werden unmittelbar beim Anfordern versendet. Jeder erfolgreiche Sofortversand zählt zum gleichen rollierenden 60-Minuten-Limit. Die unten konfigurierte Reserve verhindert, dass normale Warteschlangen-Mails vorher die gesamte Stundenkapazität belegen.</div>
<div class="grid">
<label>Worker-Batch-Größe<input type="number" name="worker_batch_size" min="1" max="500" value="<?= $workerBatchSize ?>" required><small>Maximal so viele wartende Einträge verarbeitet ein einzelner Aufruf von <code>mail:work</code>.</small></label>
<label>Maximal pro 60 Minuten<input type="number" name="max_per_hour" min="1" max="10000" value="<?= $maxPerHour ?>" required><small>Gesamtlimit aller erfolgreich versandten produktiven Mails, einschließlich Sofortmails. Es handelt sich um ein rollierendes 60-Minuten-Fenster.</small></label>
<label>Reserve für Sofortmails<input type="number" name="immediate_reserve_per_hour" min="0" max="<?= $maxPerHour ?>" value="<?= $immediateReservePerHour ?>" required><small>So viele Plätze des 60-Minuten-Limits hält der Worker zunächst für Sofortmails frei. Bereits versandte Sofortmails verbrauchen diese Reserve. 0 deaktiviert die Reserve.</small></label>
<label class="wide">Retry-Abstände in Minuten<input name="retry_minutes" value="<?= $e($retryMinutes) ?>" placeholder="15, 60, 360" required><small>Nach dem 1. Fehlschlag z. B. nach 15 Minuten, nach dem 2. nach 60 und nach dem 3. nach 360 Minuten. Ist kein weiterer Abstand vorhanden, endet die Mail als fehlgeschlagen.</small></label>
<label>Processing-Timeout in Minuten<input type="number" name="processing_timeout_minutes" min="1" max="120" value="<?= $processingTimeoutMinutes ?>" required><small>Crash-Schutz: Bleibt eine Mail während eines abgebrochenen Worker-Laufs auf „processing“, darf sie danach erneut beansprucht werden. Dies ist kein SMTP-Verbindungs-Timeout.</small></label>
</div><button class="button" type="submit">E-Mail-Konfiguration speichern</button></form>
<section class="card stack"><h2>Test-E-Mail</h2><p>Der Test verwendet ausschließlich die aktuell <strong>gespeicherte</strong> SMTP-Konfiguration. Änderungen daher zuerst speichern. Die manuell ausgelöste SMTP-Testmail wird nicht über die produktive Warteschlange gezählt.</p>
<form method="post" action="/admin/config/mail/test" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><label>Empfängeradresse<input type="email" name="test_recipient" required placeholder="name@example.de"></label><button class="button button-secondary" type="submit" <?= $smtpConfigured ? '' : 'disabled' ?>>Test-E-Mail senden</button></form></section>
</main></body></html>
