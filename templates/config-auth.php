<?php

declare(strict_types=1);
use FachDock\Auth\AuthenticatedStaff;
/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var int $passwordMinLength */
/** @var int $maxFailedAttempts */
/** @var int $lockoutMinutes */
/** @var int $sessionMaxLifetimeMinutes */
/** @var int $sessionIdleTimeoutMinutes */
/** @var int $parentMagicLinkMinutes */
/** @var int $parentSessionLifetimeMinutes */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Anmeldung & Sitzungen · FachDock</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack"><header class="hero"><span class="eyebrow">Konfiguration</span><h1>Anmeldung & Sitzungen</h1><p>Sicherheitsgrenzen für lokale Mitarbeitendenkonten und das Elternportal.</p></header>
<p><a href="/admin/config">← Zur Konfigurationsübersicht</a></p>
<?php if ($success): ?><div class="alert alert-success">Die Anmelde- und Sitzungseinstellungen wurden gespeichert.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="card stack" method="post" action="/admin/config/auth"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<h2>Mitarbeitenden-Anmeldung</h2><div class="grid">
<label>Passwort-Mindestlänge<input type="number" name="password_min_length" min="8" max="128" value="<?= $passwordMinLength ?>" required></label>
<label>Maximale Fehlversuche<input type="number" name="max_failed_attempts" min="1" max="20" value="<?= $maxFailedAttempts ?>" required></label>
<label>Sperrdauer in Minuten<input type="number" name="lockout_minutes" min="1" max="1440" value="<?= $lockoutMinutes ?>" required></label>
</div>
<h2>Mitarbeitenden-Sitzungen</h2><div class="grid">
<label>Maximale Sitzungsdauer in Minuten<input type="number" name="session_max_lifetime_minutes" min="15" max="10080" value="<?= $sessionMaxLifetimeMinutes ?>" required><small>Absolute Obergrenze unabhängig von Aktivität.</small></label>
<label>Inaktivitätslimit in Minuten<input type="number" name="session_idle_timeout_minutes" min="5" max="1440" value="<?= $sessionIdleTimeoutMinutes ?>" required><small>Darf die maximale Sitzungsdauer nicht überschreiten.</small></label>
</div>
<h2>Elternportal</h2><div class="grid">
<label>Magic-Link-Gültigkeit in Minuten<input type="number" name="parent_magic_link_minutes" min="5" max="1440" value="<?= $parentMagicLinkMinutes ?>" required></label>
<label>Eltern-Sitzungsdauer in Minuten<input type="number" name="parent_session_lifetime_minutes" min="15" max="10080" value="<?= $parentSessionLifetimeMinutes ?>" required></label>
</div>
<div class="alert alert-neutral">Neue Werte gelten für neu bewertete Anmeldungen und Sitzungen. Bereits widerrufene Sitzungen werden dadurch nicht reaktiviert.</div>
<button class="button" type="submit">Anmeldeeinstellungen speichern</button></form></main></body></html>
