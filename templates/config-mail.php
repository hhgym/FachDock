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
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>E-Mail & SMTP · FachDock</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack"><header class="hero"><span class="eyebrow">Konfiguration</span><h1>E-Mail & SMTP</h1><p>Transportkonfiguration und technische Parameter der Versandwarteschlange.</p></header>
<p><a href="/admin/config">← Zur Konfigurationsübersicht</a> · <a href="/admin/mail">Vorlagen & Warteschlange öffnen</a></p>
<?php if ($success): ?><div class="alert alert-success">Die E-Mail-Konfiguration wurde gespeichert.</div><?php endif; ?>
<?php if ($testSuccess): ?><div class="alert alert-success">Die Test-E-Mail wurde vom SMTP-Server angenommen.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="card stack"><div class="school-year-heading"><div><h2>Status</h2><p class="form-hint">Das SMTP-Passwort wird niemals angezeigt.</p></div><span class="badge"><?= $smtpConfigured ? 'SMTP konfiguriert' : 'SMTP unvollständig' ?></span></div>
<div class="alert alert-neutral">Die Warteschlange wird nur verarbeitet, wenn <code>bin/fachdock mail:work</code> regelmäßig ausgeführt wird, üblicherweise per Cronjob. Die Webkonfiguration ersetzt diesen Worker nicht.</div></section>
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
<h2>Versandwarteschlange</h2><div class="grid">
<label>Worker-Batch-Größe<input type="number" name="worker_batch_size" min="1" max="500" value="<?= $workerBatchSize ?>" required><small>Maximal verarbeitete Einträge pro Worker-Lauf.</small></label>
<label>Maximal pro Stunde<input type="number" name="max_per_hour" min="1" max="10000" value="<?= $maxPerHour ?>" required><small>Globales stündliches Versandlimit.</small></label>
<label class="wide">Retry-Abstände in Minuten<input name="retry_minutes" value="<?= $e($retryMinutes) ?>" placeholder="15, 60, 360" required><small>1 bis 10 Werte, z. B. <code>15, 60, 360</code>. Sie werden sortiert und dedupliziert gespeichert.</small></label>
<label>Processing-Timeout in Minuten<input type="number" name="processing_timeout_minutes" min="1" max="120" value="<?= $processingTimeoutMinutes ?>" required><small>Nach dieser Zeit kann ein hängen gebliebener Versand erneut beansprucht werden.</small></label>
</div><button class="button" type="submit">E-Mail-Konfiguration speichern</button></form>
<section class="card stack"><h2>Test-E-Mail</h2><p>Der Test verwendet ausschließlich die aktuell <strong>gespeicherte</strong> SMTP-Konfiguration. Änderungen daher zuerst speichern.</p>
<form method="post" action="/admin/config/mail/test" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><label>Empfängeradresse<input type="email" name="test_recipient" required placeholder="name@example.de"></label><button class="button button-secondary" type="submit" <?= $smtpConfigured ? '' : 'disabled' ?>>Test-E-Mail senden</button></form></section>
</main></body></html>
