<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var array{payment_id:int,status:string,amount_cents:int,currency:string,booking_id:int|null,created_at:string,updated_at:string,failure_message:string|null}|null $payment */
/** @var string $csrfToken */
/** @var string|null $error */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2, ',', '.') . ' ' . strtoupper($currency);
$statusLabel = static function (string $status): string {
    return match ($status) {
        'creating', 'checkout_open' => 'Zahlung noch offen',
        'processing_paid' => 'Zahlung wird verarbeitet',
        'paid' => 'Zahlung erfolgreich',
        'failed' => 'Zahlung fehlgeschlagen',
        'expired' => 'Zahlung abgelaufen',
        'manual_review' => 'Manuelle Prüfung erforderlich',
        default => 'Zahlungsstatus unbekannt',
    };
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahlungsstatus · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent">Übersicht</a>
        <span><?= $e($parent->displayName()) ?></span>
        <form method="post" action="/parent/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>

<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Zahlung</span>
        <h1>Zahlungsstatus</h1>
        <p>Stripe meldet den endgültigen Zahlungseingang serverseitig an FachDock. Falls die Anzeige noch nicht aktualisiert ist, laden Sie die Seite erneut.</p>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php elseif ($payment !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2><?= $e($statusLabel($payment['status'])) ?></h2>
                    <p class="form-hint">Zahlung #<?= (int) $payment['payment_id'] ?> · zuletzt aktualisiert <?= $e($payment['updated_at']) ?></p>
                </div>
                <span class="badge"><?= $e($money($payment['amount_cents'], $payment['currency'])) ?></span>
            </div>

            <?php if ($payment['status'] === 'paid' && $payment['booking_id'] !== null): ?>
                <div class="alert">
                    <strong>Die Buchung ist abgeschlossen.</strong>
                    <p>Ihre Zahlung wurde bestätigt und das Schließfach verbindlich gebucht.</p>
                </div>
            <?php elseif (in_array($payment['status'], ['creating', 'checkout_open', 'processing_paid'], true)): ?>
                <div class="alert">
                    <strong>Die Zahlung wird noch verarbeitet.</strong>
                    <p>Bitte laden Sie diese Seite in Kürze erneut. Maßgeblich ist die serverseitige Zahlungsbestätigung von Stripe.</p>
                </div>
            <?php elseif ($payment['status'] === 'manual_review'): ?>
                <div class="alert alert-error">
                    <strong>Die Zahlung wurde bestätigt, die Buchung benötigt aber eine manuelle Prüfung.</strong>
                    <p>Die Schließfachverwaltung kann den Vorgang anhand der Zahlungsnummer prüfen.</p>
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <strong>Die Zahlung wurde nicht abgeschlossen.</strong>
                    <?php if ($payment['failure_message'] !== null): ?><p><?= $e($payment['failure_message']) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="topbar-actions">
                <a class="button" href="/parent">Zur Übersicht</a>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
