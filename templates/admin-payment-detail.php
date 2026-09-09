<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var array<string, mixed> $payment */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $recovered */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$statusLabel = static fn (string $status): string => match ($status) {
    'creating' => 'Wird angelegt',
    'checkout_open' => 'Checkout offen',
    'processing_paid' => 'Zahlung wird verbucht',
    'paid' => 'Bezahlt',
    'failed' => 'Fehlgeschlagen',
    'expired' => 'Abgelaufen',
    'manual_review' => 'Manuelle Prüfung',
    default => $status,
};
/** @var list<array<string, mixed>> $events */
$events = is_array($payment['webhook_events'] ?? null) ? $payment['webhook_events'] : [];
$recoverable = (string) $payment['status'] === 'manual_review'
    && in_array((string) ($payment['failure_code'] ?? ''), ['paid_booking_failed', 'paid_booking_activation_failed'], true)
    && $payment['paid_at'] !== null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahlung #<?= (int) $payment['id'] ?> · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Zahlung #<?= (int) $payment['id'] ?></span>
        <h1><?= $e($statusLabel((string) $payment['status'])) ?></h1>
        <p><a href="/admin/payments">← Zur Zahlungsübersicht</a></p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($recovered): ?>
        <div class="alert alert-success" role="status">
            <strong>Wiederherstellung abgeschlossen.</strong> Die bereits bestätigte Zahlung ist jetzt einer aktiven Buchung zugeordnet.
        </div>
    <?php endif; ?>

    <?php if ((string) $payment['status'] === 'manual_review'): ?>
        <div class="alert alert-error">
            <strong>Manuelle Prüfung erforderlich.</strong>
            Stripe hat die Zahlung bestätigt, die automatische Aktivierung der Buchung konnte jedoch nicht abgeschlossen werden. Es darf keine zweite Zahlung angefordert werden, bevor der Vorgang geklärt ist.
        </div>
    <?php elseif ((string) $payment['status'] === 'processing_paid'): ?>
        <div class="alert alert-neutral">
            <strong>Zahlung wird verarbeitet.</strong> Der bestätigte Zahlungseingang wird gerade in die zugehörige Buchung übernommen.
        </div>
    <?php endif; ?>

    <?php if ($recoverable): ?>
        <section class="card stack">
            <h2>Bezahlte Buchung wiederherstellen</h2>
            <p>Diese Aktion fordert <strong>keine neue Zahlung</strong> an. FachDock versucht ausschließlich, die bereits durch einen signierten Stripe-Webhook bestätigte Zahlung erneut mit der zugehörigen Schließfachbuchung abzuschließen.</p>
            <p class="form-hint">Bei einer reservierungsbasierten Zahlung wird die Buchungsumwandlung erneut versucht. Bei einer bereits bestehenden Buchung wird ausschließlich deren bezahlter Status aktiviert.</p>
            <form method="post" action="/admin/payments/recover">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                <button class="button" type="submit">Bezahlte Buchung wiederherstellen</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Zahlungsdaten</h2>
                <p class="form-hint">Erstellt am <?= $e((string) $payment['created_at']) ?> · zuletzt geändert <?= $e((string) $payment['updated_at']) ?></p>
            </div>
            <span class="badge"><?= $e($statusLabel((string) $payment['status'])) ?></span>
        </div>
        <div class="grid">
            <div><strong>Betrag</strong><br><?= $e(number_format((int) $payment['amount_cents'] / 100, 2, ',', '.') . ' ' . (string) $payment['currency']) ?></div>
            <div><strong>Jahresgebühr</strong><br><?= $e(number_format((int) $payment['annual_fee_cents'] / 100, 2, ',', '.') . ' ' . (string) $payment['currency']) ?><br><span class="muted"><?= (int) $payment['proration_months'] ?> berechnete Monate</span></div>
            <div><strong>Schüler</strong><br><?= $e((string) $payment['first_name'] . ' ' . (string) $payment['last_name']) ?><br><span class="muted"><?= $e((string) $payment['class_name']) ?> · <?= $e((string) $payment['matrikelnummer']) ?></span></div>
            <div><strong>Elternkonto</strong><br><?= $e((string) $payment['parent_email']) ?></div>
            <div><strong>Schuljahr / Fach</strong><br><?= $e((string) $payment['school_year_label']) ?> · <?= $e((string) $payment['locker_short_name']) ?></div>
            <div>
                <strong>Ausgangspunkt</strong><br>
                <?php if ($payment['reservation_id'] !== null): ?>
                    Reservierung #<?= (int) $payment['reservation_id'] ?> · <?= $e((string) ($payment['reservation_status'] ?? '')) ?><br>
                    <span class="muted">Gültig bis <?= $e((string) ($payment['reservation_expires_at'] ?? '')) ?></span>
                <?php elseif ($payment['booking_id'] !== null): ?>
                    bestehende Buchung #<?= (int) $payment['booking_id'] ?>
                <?php else: ?>
                    –
                <?php endif; ?>
            </div>
        </div>
        <?php if ($payment['booking_id'] !== null): ?>
            <p><a href="/admin/bookings/detail?id=<?= (int) $payment['booking_id'] ?>">Buchung #<?= (int) $payment['booking_id'] ?> öffnen</a></p>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Stripe-Referenzen</h2>
        <div class="grid">
            <div><strong>Checkout Session</strong><br><span class="code-value"><?= $payment['stripe_checkout_session_id'] !== null ? $e((string) $payment['stripe_checkout_session_id']) : '–' ?></span></div>
            <div><strong>PaymentIntent</strong><br><span class="code-value"><?= $payment['stripe_payment_intent_id'] !== null ? $e((string) $payment['stripe_payment_intent_id']) : '–' ?></span></div>
            <div><strong>Bezahlt am</strong><br><?= $payment['paid_at'] !== null ? $e((string) $payment['paid_at']) : '–' ?></div>
            <div><strong>Fehlgeschlagen am</strong><br><?= $payment['failed_at'] !== null ? $e((string) $payment['failed_at']) : '–' ?></div>
        </div>
        <?php if ($payment['failure_code'] !== null || $payment['failure_message'] !== null): ?>
            <div class="alert <?= (string) $payment['status'] === 'manual_review' ? 'alert-error' : 'alert-neutral' ?>">
                <?php if ($payment['failure_code'] !== null): ?><strong><?= $e((string) $payment['failure_code']) ?></strong><?php endif; ?>
                <?php if ($payment['failure_message'] !== null): ?><div><?= $e((string) $payment['failure_message']) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Webhook-Verlauf</h2>
                <p class="form-hint">Signierte Stripe-Ereignisse, die diesem Zahlungsvorgang zugeordnet wurden.</p>
            </div>
            <span class="badge"><?= count($events) ?> Ereignisse</span>
        </div>
        <?php if ($events === []): ?>
            <p>Noch kein Webhook-Ereignis zugeordnet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Zeit</th><th>Ereignis</th><th>Status</th><th>Stripe Event-ID</th><th>Hinweis</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= $e((string) $event['received_at']) ?></td>
                            <td><?= $e((string) $event['event_type']) ?></td>
                            <td><span class="badge"><?= $e((string) $event['status']) ?></span></td>
                            <td><span class="code-value"><?= $e((string) $event['stripe_event_id']) ?></span></td>
                            <td><?= $event['error_message'] !== null ? $e((string) $event['error_message']) : '–' ?></td>
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
