<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var array<string, mixed> $booking */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (?int $cents): string => $cents === null ? '–' : number_format($cents / 100, 2, ',', '.') . ' €';
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Aktiv',
    'exemption_review' => 'BuT-Prüfung',
    'payment_due' => 'Zahlung offen',
    'ended' => 'Beendet',
    'cancelled' => 'Storniert',
    default => $status,
};
$paymentLabel = static fn (string $status): string => match ($status) {
    'creating' => 'Wird angelegt',
    'checkout_open' => 'Checkout offen',
    'processing_paid' => 'Zahlung wird verbucht',
    'paid' => 'Bezahlt',
    'failed' => 'Fehlgeschlagen',
    'expired' => 'Abgelaufen',
    'manual_review' => 'Manuelle Prüfung',
    default => $status,
};
/** @var list<array<string, mixed>> $payments */
$payments = is_array($booking['payments'] ?? null) ? $booking['payments'] : [];
/** @var list<array<string, mixed>> $assignments */
$assignments = is_array($booking['assignments'] ?? null) ? $booking['assignments'] : [];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchung #<?= (int) $booking['id'] ?> · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchung #<?= (int) $booking['id'] ?></span>
        <h1><?= $e((string) $booking['first_name'] . ' ' . (string) $booking['last_name']) ?></h1>
        <p><a href="/admin/bookings">← Zur Buchungsübersicht</a></p>
    </header>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Buchungsdaten</h2>
                <p class="form-hint">Angelegt am <?= $e((string) $booking['created_at']) ?></p>
            </div>
            <span class="badge"><?= $e($statusLabel((string) $booking['status'])) ?></span>
        </div>
        <div class="grid">
            <div><strong>Schüler</strong><br><?= $e((string) $booking['first_name'] . ' ' . (string) $booking['last_name']) ?><br><span class="muted"><?= $e((string) $booking['class_name']) ?> · <?= $e((string) $booking['matrikelnummer']) ?></span></div>
            <div><strong>Schuljahr</strong><br><?= $e((string) $booking['school_year_label']) ?><br><span class="muted"><?= $e((string) $booking['valid_from']) ?> bis <?= $e((string) $booking['valid_until']) ?></span></div>
            <div><strong>Schließfach</strong><br><?= $booking['locker_short_name'] !== null ? $e((string) $booking['locker_short_name']) : '–' ?><br><span class="muted"><?php if ($booking['building_name'] !== null): ?><?= $e((string) $booking['building_name']) ?> · <?= $e((string) $booking['floor_name']) ?> · <?= $e((string) $booking['area_name']) ?><?php endif; ?></span></div>
            <div><strong>Zielklassenstufe</strong><br><?= (int) $booking['projected_grade'] ?></div>
            <div><strong>Jahresgebühr</strong><br><?= $e($money($booking['annual_fee_cents'] !== null ? (int) $booking['annual_fee_cents'] : null)) ?></div>
            <div><strong>Berechnet</strong><br><?= $e($money($booking['charged_fee_cents'] !== null ? (int) $booking['charged_fee_cents'] : null)) ?><?php if ($booking['proration_months'] !== null): ?><br><span class="muted"><?= (int) $booking['proration_months'] ?> Monate</span><?php endif; ?></div>
        </div>
    </section>

    <?php if ($booking['fee_exemption_type'] !== null || (string) $booking['status'] === 'payment_due'): ?>
        <section class="card stack">
            <h2>BuT / Gebührenbefreiung</h2>
            <div class="grid">
                <div><strong>Art</strong><br><?= $booking['fee_exemption_type'] !== null ? $e((string) $booking['fee_exemption_type']) : '–' ?></div>
                <div><strong>Geprüft am</strong><br><?= $booking['exemption_reviewed_at'] !== null ? $e((string) $booking['exemption_reviewed_at']) : 'Noch nicht geprüft' ?></div>
                <div><strong>Geprüft durch</strong><br><?= $booking['exemption_reviewer_name'] !== null ? $e((string) $booking['exemption_reviewer_name']) : '–' ?></div>
                <div><strong>Zahlungsfrist</strong><br><?= $booking['payment_due_at'] !== null ? $e((string) $booking['payment_due_at']) : '–' ?></div>
            </div>
            <?php if ($booking['exemption_review_note'] !== null && trim((string) $booking['exemption_review_note']) !== ''): ?>
                <div><strong>Prüfvermerk</strong><p><?= nl2br($e((string) $booking['exemption_review_note'])) ?></p></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card stack">
        <h2>Zahlungen</h2>
        <?php if ($payments === []): ?>
            <p>Für diese Buchung ist kein Stripe-Zahlungsvorgang hinterlegt. Dies ist bei BuT- oder gebührenfreien Buchungen normal.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>ID</th><th>Status</th><th>Betrag</th><th>Elternkonto</th><th>Stripe</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>#<?= (int) $payment['id'] ?></td>
                            <td><span class="badge"><?= $e($paymentLabel((string) $payment['status'])) ?></span></td>
                            <td><?= $e(number_format((int) $payment['amount_cents'] / 100, 2, ',', '.') . ' ' . (string) $payment['currency']) ?></td>
                            <td><?= $e((string) $payment['parent_email']) ?></td>
                            <td><?php if ($payment['stripe_payment_intent_id'] !== null): ?><span class="code-value"><?= $e((string) $payment['stripe_payment_intent_id']) ?></span><?php else: ?>–<?php endif; ?></td>
                            <td><a href="/admin/payments/detail?id=<?= (int) $payment['id'] ?>">Zahlungsdetails</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Schließfachverlauf</h2>
        <?php if ($assignments === []): ?>
            <p>Es ist kein Schließfachverlauf hinterlegt.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Fach</th><th>Von</th><th>Bis</th><th>Grund</th><th>Akteur</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?= $e((string) $assignment['locker_short_name']) ?></td>
                            <td><?= $e((string) $assignment['starts_at']) ?></td>
                            <td><?= $assignment['ends_at'] !== null ? $e((string) $assignment['ends_at']) : 'Aktuell' ?></td>
                            <td><?= $e((string) $assignment['reason']) ?></td>
                            <td><?= $e((string) $assignment['actor_type']) ?><?= $assignment['actor_id'] !== null ? ' #' . (int) $assignment['actor_id'] : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
