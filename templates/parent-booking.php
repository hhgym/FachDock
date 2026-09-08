<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> $children */
/** @var list<array{id: int, label: string, starts_on: string, ends_on: string, annual_fee_cents: int}> $schoolYears */
/** @var int|null $selectedStudentId */
/** @var int|null $selectedSchoolYearId */
/** @var array<string, mixed>|null $selection */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfach auswählen · FachDock</title>
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
        <h1>Schließfach auswählen</h1>
        <p>Wählen Sie zunächst Kind und Schuljahr. Angezeigt werden ausschließlich freie Schließfächer, die den hinterlegten Zuteilungsregeln entsprechen.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Auswahl</h2>
        <?php if ($children === []): ?>
            <p>Mit Ihrem Elternkonto ist derzeit kein aktiver Schüler verknüpft.</p>
        <?php elseif ($schoolYears === []): ?>
            <p>Derzeit ist kein Schuljahr für reguläre Neubuchungen geöffnet.</p>
        <?php else: ?>
            <form method="get" action="/parent/booking" class="grid">
                <label>Kind
                    <select name="student_id" required>
                        <option value="">Bitte wählen</option>
                        <?php foreach ($children as $child): ?>
                            <option value="<?= (int) $child['id'] ?>" <?= $selectedStudentId === (int) $child['id'] ? 'selected' : '' ?>>
                                <?= $e((string) $child['first_name'] . ' ' . (string) $child['last_name']) ?> · Klasse <?= $e((string) $child['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Schuljahr
                    <select name="school_year_id" required>
                        <option value="">Bitte wählen</option>
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>>
                                <?= $e((string) $year['label']) ?> · <?= $e($money((int) $year['annual_fee_cents'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button" type="submit">Freie Schließfächer anzeigen</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($selection !== null): ?>
        <?php
        /** @var array{id: int, first_name: string, last_name: string, class_name: string, grade: int} $child */
        $child = $selection['child'];
        /** @var array{id: int, label: string, starts_on: string, ends_on: string, annual_fee_cents: int} $year */
        $year = $selection['school_year'];
        $active = is_array($selection['active_reservation'] ?? null) ? $selection['active_reservation'] : null;
        /** @var list<array<string, mixed>> $recommended */
        $recommended = $selection['recommended'];
        /** @var list<array<string, mixed>> $available */
        $available = $selection['available'];
        $paymentRunning = $active !== null && (string) ($active['status'] ?? '') === 'payment_running';
        ?>

        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2><?= $e((string) $child['first_name'] . ' ' . (string) $child['last_name']) ?></h2>
                    <p class="form-hint">Aktuell Klasse <?= $e((string) $child['class_name']) ?> · Zielklassenstufe <?= (int) $selection['projected_grade'] ?> · Schuljahr <?= $e((string) $year['label']) ?></p>
                </div>
                <span class="badge"><?= $e($money((int) $year['annual_fee_cents'])) ?></span>
            </div>

            <?php if ($active !== null): ?>
                <div class="alert stack">
                    <div>
                        <strong>Reserviert: <?= $e((string) $active['short_name']) ?></strong>
                        <div><?= $e((string) $active['long_name']) ?></div>
                    </div>
                    <?php if ($paymentRunning): ?>
                        <p>Für diese Reservierung wurde bereits ein Zahlungsvorgang gestartet. Die Auswahl kann deshalb nicht mehr gewechselt oder freigegeben werden.</p>
                    <?php else: ?>
                        <p>Die Reservierung ist bis <?= $e((string) $active['expires_at']) ?> gültig. Eine andere Auswahl ersetzt diese Reservierung automatisch.</p>
                        <form method="post" action="/parent/booking/cancel">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                            <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int) $active['reservation_id'] ?>">
                            <button class="button button-secondary" type="submit">Reservierung freigeben</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($active !== null && !$paymentRunning): ?>
            <section class="card stack">
                <h2>Buchung abschließen</h2>
                <p>Mit dem nächsten Schritt wird aus der Reservierung eine verbindliche Buchung. Die Online-Zahlung per Stripe wird separat ergänzt.</p>
                <div class="entity-list">
                    <div class="entity-row stack">
                        <strong>BuT-Gebührenbefreiung</strong>
                        <p class="form-hint">Wenn für Ihr Kind eine Gebührenbefreiung nach Bildung und Teilhabe geltend gemacht wird, wird das Schließfach sofort verbindlich gebucht und anschließend durch die Schließfachverwaltung geprüft.</p>
                        <form method="post" action="/parent/booking/but">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int) $active['reservation_id'] ?>">
                            <button class="button" type="submit">BuT-Befreiung beantragen und verbindlich buchen</button>
                        </form>
                    </div>
                    <div class="entity-row stack">
                        <strong>Online bezahlen</strong>
                        <p class="form-hint">Der Stripe-Zahlungsweg wird im nächsten Buchungsbaustein aktiviert.</p>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2>Empfehlungen</h2>
                    <p class="form-hint">Die Empfehlungen berücksichtigen verbindliche Regeln und hinterlegte Präferenzen. Bei gleichem Score wird die Auswahl verteilt.</p>
                </div>
                <span class="badge"><?= count($recommended) ?> Vorschläge</span>
            </div>

            <?php if ($recommended === []): ?>
                <p>Aktuell ist kein regelkonformes freies Schließfach verfügbar.</p>
            <?php else: ?>
                <div class="entity-list">
                    <?php foreach ($recommended as $locker): ?>
                        <div class="entity-row stack">
                            <div class="school-year-heading">
                                <div>
                                    <strong><?= $e((string) $locker['short_name']) ?></strong>
                                    <div class="muted"><?= $e((string) $locker['long_name']) ?></div>
                                    <div class="muted"><?= $e((string) $locker['building_name']) ?> · <?= $e((string) $locker['floor_name']) ?> · <?= $e((string) $locker['area_name']) ?></div>
                                </div>
                                <?php if ((bool) $locker['barrier_friendly']): ?><span class="badge">barrierearm</span><?php endif; ?>
                            </div>
                            <?php if (!$paymentRunning): ?>
                                <form method="post" action="/parent/booking/reserve">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                                    <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                                    <input type="hidden" name="locker_id" value="<?= (int) $locker['locker_id'] ?>">
                                    <button class="button" type="submit">Dieses Schließfach auswählen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2>Alle verfügbaren Schließfächer</h2>
                    <p class="form-hint">Sie können auch jedes andere regelkonforme freie Schließfach direkt auswählen.</p>
                </div>
                <span class="badge"><?= count($available) ?> frei</span>
            </div>
            <?php if ($available !== []): ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Fach</th><th>Standort</th><th>Hinweis</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($available as $locker): ?>
                            <tr>
                                <td><strong><?= $e((string) $locker['short_name']) ?></strong><br><span class="muted"><?= $e((string) $locker['long_name']) ?></span></td>
                                <td><?= $e((string) $locker['building_name']) ?> · <?= $e((string) $locker['floor_name']) ?> · <?= $e((string) $locker['area_name']) ?> · Gruppe <?= $e((string) $locker['group_code']) ?></td>
                                <td><?= (bool) $locker['barrier_friendly'] ? 'barrierearm' : '–' ?></td>
                                <td>
                                    <?php if (!$paymentRunning): ?>
                                        <form method="post" action="/parent/booking/reserve">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                                            <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                                            <input type="hidden" name="locker_id" value="<?= (int) $locker['locker_id'] ?>">
                                            <button class="button button-secondary" type="submit">Auswählen</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
