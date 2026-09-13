<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> $children */
/** @var list<array{payment_id:int,status:string,amount_cents:int,currency:string,updated_at:string,student_name:string,school_year_label:string,locker_short_name:string}> $openPayments */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2, ',', '.') . ' ' . strtoupper($currency);
$paymentLabel = static fn (string $status): string => match ($status) {
    'processing_paid' => 'Zahlung wird verbucht',
    'manual_review' => 'Zahlung eingegangen – Prüfung erforderlich',
    default => 'Zahlung wird bearbeitet',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elternportal · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent/booking">Schließfach buchen</a>
        <span><?= $e($parent->displayName()) ?></span>
        <form method="post" action="/parent/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Übersicht</h1>
        <p>Hier sehen Sie laufende Zahlungsvorgänge und können für Ihre verknüpften Kinder Schließfächer auswählen.</p>
    </header>

    <?php if ($openPayments !== []): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2>Aktuelle Zahlungsvorgänge</h2>
                    <p class="form-hint">Eine Buchung wird erst nach bestätigtem Zahlungseingang endgültig angelegt. Bis dahin bleibt der Vorgang hier sichtbar.</p>
                </div>
                <span class="badge"><?= count($openPayments) ?> offen</span>
            </div>
            <div class="entity-list">
                <?php foreach ($openPayments as $payment): ?>
                    <?php $manualReview = (string) $payment['status'] === 'manual_review'; ?>
                    <div class="entity-row stack">
                        <div class="school-year-heading">
                            <div>
                                <strong><?= $e((string) $payment['student_name']) ?> · Schließfach <?= $e((string) $payment['locker_short_name']) ?></strong>
                                <div class="muted">Schuljahr <?= $e((string) $payment['school_year_label']) ?> · Zahlung #<?= (int) $payment['payment_id'] ?></div>
                            </div>
                            <span class="badge"><?= $e($money((int) $payment['amount_cents'], (string) $payment['currency'])) ?></span>
                        </div>
                        <div class="alert<?= $manualReview ? ' alert-error' : '' ?>">
                            <strong><?= $e($paymentLabel((string) $payment['status'])) ?></strong>
                            <?php if ($manualReview): ?>
                                <p>Stripe hat den Zahlungseingang bestätigt, die Buchung konnte aber noch nicht automatisch abgeschlossen werden. Die Schließfachverwaltung muss den Vorgang prüfen.</p>
                            <?php else: ?>
                                <p>Die Zahlung ist noch nicht endgültig in FachDock verbucht. Das reservierte Schließfach bleibt diesem Zahlungsvorgang zugeordnet.</p>
                            <?php endif; ?>
                        </div>
                        <div><a class="button button-secondary" href="/parent/payment/return?payment_id=<?= (int) $payment['payment_id'] ?>">Zahlungsstatus ansehen</a></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card stack">
        <h2>Ihre Kinder</h2>
        <?php if ($children === []): ?>
            <p>Mit diesem Elternkontakt ist derzeit kein aktiver Schüler verknüpft.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($children as $child): ?>
                    <div class="entity-row compact-form">
                        <strong><?= $e((string) $child['first_name'] . ' ' . (string) $child['last_name']) ?></strong>
                        <div class="muted">Klasse <?= $e((string) $child['class_name']) ?> · Klassenstufe <?= (int) $child['grade'] ?></div>
                        <div><a class="button button-secondary" href="/parent/booking?student_id=<?= (int) $child['id'] ?>">Schließfach auswählen</a></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
