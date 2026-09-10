<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var list<array<string, mixed>> $bookings */
/** @var int $changeLimit */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var string|null $success */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Aktiv',
    'exemption_review' => 'BuT-Prüfung',
    'payment_due' => 'Zahlung offen',
    default => $status,
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meine Buchungen · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent">Übersicht</a>
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
        <h1>Meine Schließfachbuchungen</h1>
        <p>Hier können Sie ein bestehendes Schließfach wechseln und die Buchung für ein Folgeschuljahr verlängern.</p>
    </header>

    <?php if ($success !== null): ?><div class="alert alert-success"><?= $e($success) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if ($bookings === []): ?>
        <section class="card stack">
            <h2>Keine aktive Buchung</h2>
            <p>Für die mit diesem Elternkonto verknüpften Schülerinnen und Schüler besteht derzeit keine aktive Schließfachbuchung.</p>
            <p><a class="button" href="/parent/booking">Schließfach auswählen</a></p>
        </section>
    <?php endif; ?>

    <?php foreach ($bookings as $booking): ?>
        <?php
        /** @var list<array{id:int,short_name:string,building_name:string,floor_name:string,area_name:string,score:int}> $lockerOptions */
        $lockerOptions = is_array($booking['locker_options'] ?? null) ? $booking['locker_options'] : [];
        /** @var list<array<string, mixed>> $renewalPlans */
        $renewalPlans = is_array($booking['renewal_plans'] ?? null) ? $booking['renewal_plans'] : [];
        $remaining = $booking['changes_remaining'] ?? null;
        ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow"><?= $e((string) $booking['school_year_label']) ?></span>
                    <h2><?= $e((string) $booking['student_name']) ?></h2>
                    <p class="form-hint">Klasse <?= $e((string) $booking['class_name']) ?> · Zielklassenstufe <?= (int) $booking['projected_grade'] ?></p>
                </div>
                <span class="badge"><?= $e($statusLabel((string) $booking['status'])) ?></span>
            </div>

            <div class="grid">
                <div><strong>Aktuelles Schließfach</strong><br><?= $e((string) $booking['locker_short_name']) ?><br><span class="muted"><?= $e((string) $booking['building_name']) ?> · <?= $e((string) $booking['floor_name']) ?> · <?= $e((string) $booking['area_name']) ?></span></div>
                <div><strong>Laufzeit</strong><br><?= $e((string) $booking['starts_on']) ?> bis <?= $e((string) $booking['ends_on']) ?></div>
                <div><strong>Wechsel</strong><br>
                    <?php if (empty($booking['change_limit_applies'])): ?>
                        Testansicht der Administration<br><span class="muted">Das Eltern-Limit wird nicht verbraucht.</span>
                    <?php elseif ($changeLimit === 0): ?>
                        Im Elternportal deaktiviert
                    <?php else: ?>
                        <?= (int) $booking['changes_used'] ?> von <?= $changeLimit ?> genutzt<br><span class="muted">Noch <?= (int) $remaining ?> möglich.</span>
                    <?php endif; ?>
                </div>
            </div>
            <p><a href="/parent/booking/status?booking_id=<?= (int) $booking['booking_id'] ?>">Buchungsstatus anzeigen</a></p>

            <div class="stack">
                <h3>Schließfach wechseln</h3>
                <p class="form-hint">Ein Wechsel ist kostenlos. Es werden nur aktuell freie und für die Klassenstufe zulässige Fächer angeboten.</p>
                <?php if (!empty($booking['change_limit_applies']) && $changeLimit === 0): ?>
                    <div class="alert alert-neutral">Der selbstständige Schließfachwechsel ist derzeit deaktiviert. Die Schließfachverwaltung kann weiterhin einen administrativen Wechsel durchführen.</div>
                <?php elseif (!empty($booking['change_limit_applies']) && (int) $remaining < 1): ?>
                    <div class="alert alert-neutral">Das Wechsel-Limit für dieses Schuljahr ist erreicht. Notwendige administrative Wechsel sind davon nicht betroffen.</div>
                <?php elseif ($lockerOptions === []): ?>
                    <p>Aktuell ist kein anderes freies und regelkonformes Schließfach verfügbar.</p>
                <?php else: ?>
                    <form class="stack" method="post" action="/parent/bookings/change-locker">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id'] ?>">
                        <label>Neues Schließfach
                            <select name="locker_id" required>
                                <option value="">Bitte auswählen</option>
                                <?php foreach ($lockerOptions as $locker): ?>
                                    <option value="<?= (int) $locker['id'] ?>"><?= $e($locker['short_name'] . ' · ' . $locker['building_name'] . ' · ' . $locker['floor_name'] . ' · ' . $locker['area_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="button" type="submit">Kostenlos wechseln</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ((string) $booking['status'] === 'active'): ?>
                <div class="stack">
                    <h3>Für ein Folgeschuljahr verlängern</h3>
                    <?php if ($renewalPlans === []): ?>
                        <p>Aktuell steht kein verlängerbares Folgeschuljahr mit einem freien regelkonformen Schließfach zur Verfügung.</p>
                    <?php else: ?>
                        <div class="entity-list">
                            <?php foreach ($renewalPlans as $plan): ?>
                                <div class="entity-row stack">
                                    <div>
                                        <strong><?= $e((string) $plan['label']) ?></strong> · Zielklassenstufe <?= (int) $plan['projected_grade'] ?> · <?= $e($money((int) $plan['annual_fee_cents'])) ?>
                                    </div>
                                    <?php if (!empty($plan['reuse_current'])): ?>
                                        <div class="alert alert-neutral">Das bisherige Schließfach <strong><?= $e((string) $plan['locker_short_name']) ?></strong> kann übernommen werden.</div>
                                    <?php else: ?>
                                        <div class="notice warning"><strong>Schließfachwechsel erforderlich:</strong> <?= $e((string) $plan['change_reason']) ?><br>Vorgesehenes freies Fach: <strong><?= $e((string) $plan['locker_short_name']) ?></strong>.</div>
                                    <?php endif; ?>
                                    <form class="stack" method="post" action="/parent/bookings/renew">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id'] ?>">
                                        <input type="hidden" name="school_year_id" value="<?= (int) $plan['school_year_id'] ?>">
                                        <?php if ((int) $plan['annual_fee_cents'] > 0): ?>
                                            <label class="check-label"><input type="checkbox" name="request_but" value="1"> BuT-Befreiung für das neue Schuljahr zur Prüfung vormerken</label>
                                        <?php endif; ?>
                                        <button class="button button-secondary" type="submit">Für <?= $e((string) $plan['label']) ?> verlängern</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
