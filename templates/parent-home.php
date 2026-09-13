<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> $children */
/** @var list<array{payment_id:int,status:string,amount_cents:int,currency:string,updated_at:string,student_name:string,school_year_label:string,locker_short_name:string}> $openPayments */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
        <a href="/parent/bookings">Meine Buchungen</a>
        <a href="/parent/booking">Schließfach buchen</a>
        <a href="/parent/support">Problem melden</a>
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
        <p>Für verknüpfte Kinder können Sie regelkonforme freie Schließfächer über den Lageplan oder die Listenansicht auswählen, bestehende Buchungen verwalten und Probleme direkt an die Schließfachverwaltung melden.</p>
    </header>

    <?php if ($openPayments !== []): ?>
        <section class="card stack">
            <div>
                <h2>Aktuelle Zahlungsvorgänge</h2>
                <p class="muted">Eine Buchung wird erst nach bestätigter Zahlung angelegt. Bis dahin bleibt das ausgewählte Schließfach dem laufenden Zahlungsvorgang zugeordnet.</p>
            </div>
            <div class="entity-list">
                <?php foreach ($openPayments as $payment): ?>
                    <?php
                    $label = match ($payment['status']) {
                        'processing_paid' => 'Zahlung wird verbucht',
                        'manual_review' => 'Zahlung eingegangen – Prüfung erforderlich',
                        default => 'Zahlung wird bearbeitet',
                    };
                    ?>
                    <div class="entity-row compact-form">
                        <strong><?= $e($payment['student_name']) ?> · Schließfach <?= $e($payment['locker_short_name']) ?></strong>
                        <div class="muted"><?= $e($payment['school_year_label']) ?> · <?= number_format($payment['amount_cents'] / 100, 2, ',', '.') ?> <?= $e(strtoupper($payment['currency'])) ?></div>
                        <div><span class="badge"><?= $e($label) ?></span></div>
                        <div class="compact-actions">
                            <a class="button button-secondary" href="/parent/payment/return?payment_id=<?= (int) $payment['payment_id'] ?>">Zahlungsstatus ansehen</a>
                        </div>
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
                        <div class="compact-actions">
                            <a class="button button-secondary" href="/parent/bookings">Buchungen verwalten</a>
                            <a class="button" href="/parent/booking/map?student_id=<?= (int) $child['id'] ?>">Auf Lageplan buchen</a>
                            <a class="button button-secondary" href="/parent/booking?student_id=<?= (int) $child['id'] ?>">Listenansicht</a>
                            <a class="button button-secondary" href="/parent/support">Problem melden</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
