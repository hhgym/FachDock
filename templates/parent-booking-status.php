<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var array{booking_id: int, status: string, school_year_label: string, locker_short_name: string, charged_fee_cents: int, payment_due_at: string|null, exemption_review_note: string|null} $booking */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$statusLabel = match ((string) $booking['status']) {
    'exemption_review' => 'BuT-Prüfung offen',
    'active' => 'Buchung aktiv',
    'payment_due' => 'Zahlung erforderlich',
    'ended' => 'Buchung beendet',
    'cancelled' => 'Buchung storniert',
    default => (string) $booking['status'],
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungsstatus · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent">Übersicht</a>
        <a href="/parent/booking">Schließfach buchen</a>
        <span><?= $e($parent->displayName()) ?></span>
        <form method="post" action="/parent/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Buchung #<?= (int) $booking['booking_id'] ?></span>
        <h1><?= $e($statusLabel) ?></h1>
        <p>Schuljahr <?= $e((string) $booking['school_year_label']) ?> · Schließfach <?= $e((string) $booking['locker_short_name']) ?></p>
    </header>

    <section class="card stack">
        <?php if ((string) $booking['status'] === 'exemption_review'): ?>
            <h2>BuT-Nachweis wird geprüft</h2>
            <p>Die Buchung ist bereits verbindlich und das Schließfach für Ihr Kind belegt. Die Schließfachverwaltung prüft nun die Gebührenbefreiung.</p>
            <p>Bis zur Entscheidung ist keine Zahlung erforderlich.</p>
        <?php elseif ((string) $booking['status'] === 'active'): ?>
            <h2>Gebührenbefreiung bestätigt</h2>
            <p>Die BuT-Befreiung wurde bestätigt. Für diese Buchung ist kein Entgelt zu zahlen.</p>
            <?php if ($booking['exemption_review_note'] !== null): ?><p class="form-hint">Hinweis: <?= $e((string) $booking['exemption_review_note']) ?></p><?php endif; ?>
        <?php elseif ((string) $booking['status'] === 'payment_due'): ?>
            <h2>BuT-Befreiung nicht bestätigt</h2>
            <p>Für die Buchung sind <?= $e($money((int) $booking['charged_fee_cents'])) ?> zu zahlen.</p>
            <?php if ($booking['payment_due_at'] !== null): ?><p>Zahlungsfrist: <?= $e((string) $booking['payment_due_at']) ?></p><?php endif; ?>
            <?php if ($booking['exemption_review_note'] !== null): ?><p class="form-hint">Begründung: <?= $e((string) $booking['exemption_review_note']) ?></p><?php endif; ?>
            <p class="form-hint">Die Online-Zahlung wird im nächsten Ausbauschritt mit Stripe verbunden.</p>
        <?php else: ?>
            <p>Aktueller Status: <?= $e($statusLabel) ?></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
