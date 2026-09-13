<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var array{id:int,first_name:string,last_name:string,class_name:string,grade:int} $child */
/** @var array{id:int,label:string,starts_on:string,ends_on:string,annual_fee_cents:int} $schoolYear */
/** @var array<string,mixed> $reservation */
/** @var array{months:int,charged_cents:int} $feeQuote */
/** @var bool $paymentCancelled */
/** @var bool $stripeCheckoutAvailable */
/** @var string $stripeMode */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$paymentRunning = (string) ($reservation['status'] ?? '') === 'payment_running';
$reservationDeadline = $paymentRunning && trim((string) ($reservation['payment_grace_expires_at'] ?? '')) !== ''
    ? (string) $reservation['payment_grace_expires_at']
    : (string) $reservation['expires_at'];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchung abschließen · FachDock</title>
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
        <span class="eyebrow">Buchung</span>
        <h1>Buchung abschließen</h1>
        <p>Prüfen Sie die Auswahl. Das Schließfach ist für kurze Zeit reserviert und wird erst mit dem Abschluss der Buchung verbindlich zugeordnet.</p>
    </header>

    <?php if ($paymentCancelled): ?>
        <div class="alert alert-neutral" role="status">
            <strong>Die Zahlung wurde noch nicht abgeschlossen.</strong>
            <p>Ihre Reservierung besteht weiter. Sie können die bereits gestartete Stripe-Zahlung unten fortsetzen.</p>
        </div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <span class="eyebrow">Zusammenfassung</span>
                <h2><?= $e((string) $reservation['short_name']) ?></h2>
                <p class="form-hint"><?= $e((string) $reservation['long_name']) ?></p>
            </div>
            <span class="badge"><?= $paymentRunning ? 'Zahlung gestartet' : 'reserviert' ?></span>
        </div>

        <div class="entity-list">
            <div class="entity-row school-year-heading">
                <span>Schüler</span>
                <strong><?= $e((string) $child['first_name'] . ' ' . (string) $child['last_name']) ?> · Klasse <?= $e((string) $child['class_name']) ?></strong>
            </div>
            <div class="entity-row school-year-heading">
                <span>Schuljahr</span>
                <strong><?= $e((string) $schoolYear['label']) ?></strong>
            </div>
            <div class="entity-row school-year-heading">
                <span>Jahresgebühr</span>
                <strong><?= $e($money((int) $schoolYear['annual_fee_cents'])) ?></strong>
            </div>
            <div class="entity-row school-year-heading">
                <span>Heute fällig</span>
                <strong><?= $e($money((int) $feeQuote['charged_cents'])) ?></strong>
            </div>
            <div class="entity-row school-year-heading">
                <span>Berechnungszeitraum</span>
                <strong><?= (int) $feeQuote['months'] ?> von 12 Monaten</strong>
            </div>
            <div class="entity-row school-year-heading">
                <span><?= $paymentRunning ? 'Zahlung möglich bis' : 'Reserviert bis' ?></span>
                <strong><?= $e($reservationDeadline) ?></strong>
            </div>
        </div>
        <?php if ((int) $feeQuote['charged_cents'] < (int) $schoolYear['annual_fee_cents']): ?>
            <p class="form-hint">Da die Buchung nach Beginn des Schuljahres erfolgt, wird die Jahresgebühr anteilig für die verbleibenden Schulmonate berechnet.</p>
        <?php endif; ?>
    </section>

    <?php if ($paymentRunning): ?>
        <section class="card stack">
            <div>
                <span class="eyebrow">Zahlung</span>
                <h2>Zahlung fortsetzen</h2>
            </div>
            <div class="alert alert-neutral">
                <strong>Der Stripe-Checkout ist bereits geöffnet.</strong>
                <p>Es wird kein neuer Zahlungsvorgang angelegt. Mit dem folgenden Button öffnen Sie denselben noch laufenden Checkout erneut.</p>
            </div>
            <form method="post" action="/parent/payment/start">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                <button class="button" type="submit">Zahlung fortsetzen · <?= $e($money((int) $feeQuote['charged_cents'])) ?></button>
            </form>
            <a class="button button-secondary" href="/parent">Zur Übersicht</a>
        </section>
    <?php else: ?>
        <section class="card stack">
            <div>
                <span class="eyebrow">Abschluss</span>
                <h2>Wie möchten Sie die Buchung abschließen?</h2>
            </div>

            <?php if ((int) $schoolYear['annual_fee_cents'] === 0): ?>
                <p>Für dieses Schuljahr fällt keine Schließfachgebühr an. Mit dem folgenden Schritt wird die Buchung sofort verbindlich.</p>
                <form method="post" action="/parent/payment/start">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                    <button class="button" type="submit">Buchung abschließen</button>
                </form>
            <?php else: ?>
                <div class="entity-list">
                    <div class="entity-row stack">
                        <div class="school-year-heading">
                            <div>
                                <strong>Online bezahlen</strong>
                                <p class="form-hint">Sie werden im nächsten Schritt zu Stripe weitergeleitet. Nach bestätigter Zahlung wird das Schließfach verbindlich gebucht.</p>
                            </div>
                            <?php if ($stripeMode === 'test'): ?><span class="badge">Stripe-Testmodus</span><?php endif; ?>
                        </div>
                        <?php if ($stripeMode === 'test'): ?>
                            <div class="alert alert-neutral"><strong>Testbetrieb:</strong> Es werden ausschließlich Stripe-Testzahlungen verwendet.</div>
                        <?php endif; ?>
                        <?php if ($stripeCheckoutAvailable): ?>
                            <form method="post" action="/parent/payment/start">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                                <button class="button" type="submit">Buchung abschließen · <?= $e($money((int) $feeQuote['charged_cents'])) ?></button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-neutral">Die Online-Zahlung ist derzeit nicht vollständig eingerichtet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="entity-row stack">
                        <strong>BuT-Gebührenbefreiung</strong>
                        <p class="form-hint">Wenn für Ihr Kind eine Gebührenbefreiung nach Bildung und Teilhabe gilt, können Sie die Buchung ohne Stripe-Zahlung verbindlich abschließen.</p>
                        <form method="post" action="/parent/booking/but">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                            <button class="button button-secondary" type="submit">BuT-Befreiung beantragen</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="card stack">
            <h2>Auswahl ändern</h2>
            <p class="form-hint">Sie können vor dem Buchungsabschluss noch ein anderes Schließfach auswählen. Die bisherige Reservierung wird dabei ersetzt.</p>
            <div class="topbar-actions">
                <a class="button button-secondary" href="/parent/booking/map?student_id=<?= (int) $child['id'] ?>&amp;school_year_id=<?= (int) $schoolYear['id'] ?>">Anderes Schließfach auswählen</a>
                <form method="post" action="/parent/booking/cancel">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                    <input type="hidden" name="school_year_id" value="<?= (int) $schoolYear['id'] ?>">
                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['reservation_id'] ?>">
                    <button class="button button-secondary" type="submit">Reservierung freigeben</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
