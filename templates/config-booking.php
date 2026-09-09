<?php

declare(strict_types=1);
use FachDock\Auth\AuthenticatedStaff;
/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var int $recommendationCount */
/** @var int $reservationMinutes */
/** @var int $paymentGraceMinutes */
/** @var int $butRejectionPaymentDays */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Buchungen · FachDock</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack"><header class="hero"><span class="eyebrow">Konfiguration</span><h1>Buchungen</h1><p>Zeitgrenzen und Vorschlagsparameter des Buchungsworkflows.</p></header>
<p><a href="/admin/config">← Zur Konfigurationsübersicht</a></p>
<?php if ($success): ?><div class="alert alert-success">Die Buchungseinstellungen wurden gespeichert.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="card stack" method="post" action="/admin/config/booking"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><div class="grid">
<label>Anzahl Empfehlungen<input type="number" name="recommendation_count" min="1" max="20" value="<?= $recommendationCount ?>" required><small>Wie viele regelkonforme Schließfächer standardmäßig vorgeschlagen werden.</small></label>
<label>Reservierungsdauer in Minuten<input type="number" name="reservation_minutes" min="5" max="120" value="<?= $reservationMinutes ?>" required><small>Zeitfenster, in dem ein ausgewähltes Fach für den Buchenden reserviert bleibt.</small></label>
<label>Zahlungs-Gnadenfrist in Minuten<input type="number" name="payment_grace_minutes" min="5" max="180" value="<?= $paymentGraceMinutes ?>" required><small>Zusätzliche Sicherung während eines gestarteten Zahlungsvorgangs.</small></label>
<label>Zahlungsfrist nach BuT-Ablehnung in Tagen<input type="number" name="but_rejection_payment_days" min="1" max="90" value="<?= $butRejectionPaymentDays ?>" required><small>Frist für die nachträgliche Zahlung einer bereits belegten Buchung.</small></label>
</div><button class="button" type="submit">Buchungseinstellungen speichern</button></form>
<section class="card stack"><h2>Self-Service und Schließfachwechsel</h2><p>Konfigurieren Sie das jährliche Wechsel-Limit für Eltern. Administrative Wechsel bleiben davon unabhängig.</p><p><a class="button button-secondary" href="/admin/config/booking/self-service">Self-Service konfigurieren</a></p></section>
</main></body></html>
