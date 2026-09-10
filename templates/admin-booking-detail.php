<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var array<string, mixed> $booking */
/** @var list<array<string, mixed>> $renewalTargets */
/** @var list<array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int}> $lockerOptions */
/** @var list<array<string, mixed>> $lifecycleEvents */
/** @var list<string> $errors */
/** @var string|null $success */
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
$eventLabel = static fn (string $event): string => match ($event) {
    'locker_changed' => 'Fachwechsel',
    'renewed' => 'Verlängert',
    'ended' => 'Beendet',
    'cancelled' => 'Storniert',
    default => $event,
};
/** @var list<array<string, mixed>> $payments */
$payments = is_array($booking['payments'] ?? null) ? $booking['payments'] : [];
/** @var list<array<string, mixed>> $assignments */
$assignments = is_array($booking['assignments'] ?? null) ? $booking['assignments'] : [];
$activeLifecycle = in_array((string) $booking['status'], ['active', 'exemption_review', 'payment_due'], true);
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

    <?php if ($success !== null): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

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

    <?php if ($activeLifecycle): ?>
        <section class="card stack">
            <div>
                <span class="eyebrow">Buchungslebenszyklus</span>
                <h2>Schließfach wechseln</h2>
                <p class="form-hint">Der Wechsel erfolgt atomar. Das bisherige Fach wird freigegeben und der Verlauf bleibt vollständig erhalten.</p>
            </div>
            <?php if ($lockerOptions === []): ?>
                <p>Aktuell ist kein anderes regelkonformes und freies Schließfach verfügbar.</p>
            <?php else: ?>
                <form class="stack" method="post" action="/admin/bookings/change-locker">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <label>Neues Schließfach
                        <select name="locker_id" required>
                            <option value="">Bitte auswählen</option>
                            <?php foreach ($lockerOptions as $locker): ?>
                                <option value="<?= (int) $locker['id'] ?>"><?= $e($locker['short_name'] . ' · ' . $locker['building_name'] . ' · ' . $locker['floor_name'] . ' · ' . $locker['area_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Begründung
                        <textarea name="reason" rows="3" maxlength="1000" required placeholder="z. B. Defekt, Barrierefreiheit oder organisatorischer Wechsel"></textarea>
                    </label>
                    <button class="button" type="submit">Schließfach wechseln</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ((string) $booking['status'] === 'active' && $renewalTargets !== []): ?>
            <section class="card stack">
                <div>
                    <span class="eyebrow">Folgeschuljahr</span>
                    <h2>Buchung verlängern</h2>
                    <p class="form-hint">FachDock übernimmt das bisherige Schließfach, solange es im Zielschuljahr frei und regelkonform bleibt. Beim Übergang von Klasse 6 zu 7 sowie bei Belegung oder Regelkonflikten wird ein regelkonformes Ersatzfach verwendet.</p>
                </div>
                <form class="stack" method="post" action="/admin/bookings/renew">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <label>Zielschuljahr
                        <select name="school_year_id" required>
                            <option value="">Bitte auswählen</option>
                            <?php foreach ($renewalTargets as $year): ?>
                                <option value="<?= (int) $year['id'] ?>"><?= $e((string) $year['label']) ?> · <?= $e($money((int) $year['annual_fee_cents'])) ?> · <?= !empty($year['reuse_current']) ? 'Fach ' . $e((string) $year['locker_short_name']) . ' bleibt' : 'Wechsel auf ' . $e((string) $year['locker_short_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="alert alert-neutral">Die angezeigte Fachzuordnung ist eine Vorschau. Beim Speichern wird die Verfügbarkeit erneut geprüft; bei einer zwischenzeitlichen Belegung kann ein anderes regelkonformes Fach gewählt werden.</div>
                    <label class="check-label"><input type="checkbox" name="request_but" value="1"> BuT-Befreiung für das neue Schuljahr erneut zur Prüfung vormerken</label>
                    <div class="alert alert-neutral">Ohne BuT-Vormerkung wird bei einer gebührenpflichtigen Verlängerung eine neue Buchung mit Status „Zahlung offen“ angelegt. Bei 0 € Jahresgebühr wird sie unmittelbar aktiv.</div>
                    <button class="button" type="submit">Verlängerung anlegen</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="card stack">
            <div>
                <span class="eyebrow">Abschluss</span>
                <h2>Buchung beenden oder stornieren</h2>
                <p class="form-hint">Beide Aktionen geben das Schließfach frei und beenden die aktive Zuordnung. Bereits verbuchte Zahlungen werden dadurch nicht automatisch erstattet.</p>
            </div>
            <div class="grid">
                <form class="stack" method="post" action="/admin/bookings/end">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <label>Grund für die Beendigung
                        <textarea name="reason" rows="3" maxlength="1000" required></textarea>
                    </label>
                    <button class="button" type="submit">Buchung beenden</button>
                </form>
                <form class="stack" method="post" action="/admin/bookings/cancel">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <label>Grund für die Stornierung
                        <textarea name="reason" rows="3" maxlength="1000" required></textarea>
                    </label>
                    <button class="button button-secondary" type="submit">Buchung stornieren</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

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
        <h2>Buchungsereignisse</h2>
        <?php if ($lifecycleEvents === []): ?>
            <p>Für diese Buchung sind noch keine Lebenszyklusänderungen hinterlegt.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Ereignis</th><th>Zeitpunkt</th><th>Fach</th><th>Verknüpfung</th><th>Begründung</th><th>Akteur</th></tr></thead>
                    <tbody>
                    <?php foreach ($lifecycleEvents as $event): ?>
                        <tr>
                            <td><span class="badge"><?= $e($eventLabel((string) $event['event_type'])) ?></span></td>
                            <td><?= $e((string) $event['created_at']) ?></td>
                            <td><?php if ($event['old_locker_name'] !== null || $event['new_locker_name'] !== null): ?><?= $event['old_locker_name'] !== null ? $e((string) $event['old_locker_name']) : '–' ?> → <?= $event['new_locker_name'] !== null ? $e((string) $event['new_locker_name']) : '–' ?><?php else: ?>–<?php endif; ?></td>
                            <td><?php if ($event['related_booking_id'] !== null): ?><a href="/admin/bookings/detail?id=<?= (int) $event['related_booking_id'] ?>">Buchung #<?= (int) $event['related_booking_id'] ?></a><?php else: ?>–<?php endif; ?></td>
                            <td><?= $e((string) $event['reason']) ?></td>
                            <td><?= $event['actor_name'] !== null ? $e((string) $event['actor_name']) : $e((string) $event['actor_type']) ?></td>
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
