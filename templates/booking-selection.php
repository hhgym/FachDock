<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\View\LockerGridRenderer;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $students */
/** @var list<array<string, mixed>> $schoolYears */
/** @var int|null $selectedStudentId */
/** @var int|null $selectedSchoolYearId */
/** @var array<string, mixed>|null $selectedStudent */
/** @var int|null $projectedGrade */
/** @var array<string, mixed>|null $activeReservation */
/** @var list<array<string, mixed>> $recommended */
/** @var list<array<string, mixed>> $available */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$paymentRunning = $activeReservation !== null && (string) $activeReservation['status'] === 'payment_running';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungsauswahl · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Buchungsauswahl</div>
    <div class="topbar-actions">
        <a href="/admin/recommendations">Empfehlungsvorschau</a>
        <a href="/admin/allocation-rules">Zuteilungsregeln</a>
        <a href="/">Dashboard</a>
    </div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungsablauf</span>
        <h1>Schließfach auswählen und reservieren</h1>
        <p>Administrativer Funktionstest des Ablaufs von Schüler und Zielschuljahr bis zur echten zeitlich begrenzten Reservierung.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Schüler und Schuljahr</h2>
        <form method="get" action="/admin/booking-selection" class="grid">
            <label>Schüler
                <select name="student_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                            <?= $e((string) $student['class_name']) ?> · <?= $e((string) $student['last_name']) ?>, <?= $e((string) $student['first_name']) ?> · <?= $e((string) $student['matrikelnummer']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Schuljahr
                <select name="school_year_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>><?= $e((string) $year['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" type="submit">Auswahl laden</button>
        </form>
        <p class="form-hint">Für diesen administrativen Test darf auch ein zukünftiges Schuljahr vor seinem öffentlichen Öffnungsdatum geprüft werden. Geschlossene Schuljahre bleiben gesperrt.</p>
    </section>

    <?php if ($selectedStudent !== null && $selectedSchoolYearId !== null && $projectedGrade !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow">Zielperson</span>
                    <h2><?= $e((string) $selectedStudent['first_name']) ?> <?= $e((string) $selectedStudent['last_name']) ?></h2>
                    <p class="form-hint">
                        <?= $e((string) $selectedStudent['class_name']) ?> · aktuelle Klassenstufe <?= (int) $selectedStudent['grade'] ?>
                        · Zielklassenstufe <strong><?= $projectedGrade ?></strong>
                    </p>
                </div>
                <span class="badge"><?= count($available) ?> frei und regelkonform</span>
            </div>
        </section>

        <?php if ($activeReservation !== null): ?>
            <section class="card stack reservation-active">
                <div class="school-year-heading">
                    <div>
                        <span class="eyebrow">Aktive Reservierung</span>
                        <h2><code><?= $e((string) $activeReservation['short_name']) ?></code></h2>
                        <p><?= $e((string) $activeReservation['long_name']) ?></p>
                    </div>
                    <span class="badge"><?= $paymentRunning ? 'Zahlung läuft' : '15-Minuten-Reservierung' ?></span>
                </div>
                <div class="reservation-meta">
                    <span>Zielklassenstufe <?= (int) $activeReservation['projected_grade'] ?></span>
                    <?php if ($paymentRunning): ?>
                        <span>Gnadenfrist bis <?= $e((string) ($activeReservation['payment_grace_expires_at'] ?? '–')) ?></span>
                    <?php else: ?>
                        <span>Reserviert bis <?= $e((string) $activeReservation['expires_at']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($paymentRunning): ?>
                    <div class="alert alert-neutral">Während eines laufenden Zahlungsvorgangs kann kein anderes Schließfach gewählt werden.</div>
                <?php else: ?>
                    <p class="form-hint">Die Auswahl eines anderen Fachs ersetzt diese Reservierung automatisch. Alternativ kann sie sofort freigegeben werden.</p>
                    <form method="post" action="/admin/booking-selection/cancel">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                        <input type="hidden" name="reservation_id" value="<?= (int) $activeReservation['reservation_id'] ?>">
                        <button class="button button-secondary" type="submit">Reservierung freigeben</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card stack">
            <h2>Empfohlene Schließfächer</h2>
            <p class="form-hint">Höchster Soft-Score zuerst; bei gleichem Score wird zufällig verteilt. Die Auswahl wird serverseitig nochmals vollständig geprüft und erst dann atomar reserviert.</p>
            <?php if ($recommended === []): ?>
                <div class="alert alert-neutral">Derzeit gibt es kein weiteres verfügbares und regelkonformes Schließfach.</div>
            <?php else: ?>
                <div class="recommendation-grid">
                    <?php foreach ($recommended as $index => $locker): ?>
                        <article class="recommendation-card stack">
                            <div>
                                <span class="eyebrow">Empfehlung <?= $index + 1 ?></span>
                                <h3><code><?= $e((string) $locker['short_name']) ?></code></h3>
                                <p><?= $e((string) $locker['floor_code']) ?> · Bereich <?= $e((string) $locker['area_code']) ?> · Gruppe <?= $e((string) $locker['group_code']) ?></p>
                                <p class="form-hint">Soft-Score <?= (int) $locker['score'] ?><?php if ((bool) $locker['barrier_friendly']): ?> · barrierearm<?php endif; ?></p>
                            </div>
                            <form method="post" action="/admin/booking-selection/reserve">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                <input type="hidden" name="locker_id" value="<?= (int) $locker['locker_id'] ?>">
                                <button class="button" type="submit" <?= $paymentRunning ? 'disabled' : '' ?>>Dieses Schließfach auswählen</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2>Alle verfügbaren Schließfächer</h2>
                    <p class="form-hint">Die Rasteransicht ordnet die Fächer nach Schrankgruppe, Korpus und Fachposition.</p>
                </div>
                <span class="badge"><?= count($available) ?> frei</span>
            </div>
            <?php if ($available === []): ?>
                <p>Keine weiteren verfügbaren Schließfächer.</p>
            <?php else: ?>
                <details class="entity-row" open>
                    <summary>Rasterauswahl</summary>
                    <div class="compact-form stack">
                        <?= LockerGridRenderer::formGrid(
                            $available,
                            '/admin/booking-selection/reserve',
                            [
                                '_csrf' => $csrfToken,
                                'student_id' => (int) $selectedStudentId,
                                'school_year_id' => (int) $selectedSchoolYearId,
                            ],
                            'locker_id',
                            'auswählen',
                            $paymentRunning,
                        ) ?>
                        <p class="form-hint">Leere Rasterzellen stehen in dieser Buchungsauswahl nicht zur Verfügung.</p>
                    </div>
                </details>
                <details class="entity-row">
                    <summary>Listenansicht</summary>
                    <div class="compact-form table-scroll">
                        <table class="data-table">
                            <thead><tr><th>Fach</th><th>Langbezeichnung</th><th>Etage</th><th>Bereich</th><th>Score</th><th>Merkmal</th><th>Auswahl</th></tr></thead>
                            <tbody>
                            <?php foreach ($available as $locker): ?>
                                <tr>
                                    <td><code><?= $e((string) $locker['short_name']) ?></code></td>
                                    <td><?= $e((string) $locker['long_name']) ?></td>
                                    <td><?= $e((string) $locker['floor_code']) ?></td>
                                    <td><?= $e((string) $locker['area_code']) ?> · <?= $e((string) $locker['area_name']) ?></td>
                                    <td><?= (int) $locker['score'] ?></td>
                                    <td><?= (bool) $locker['barrier_friendly'] ? 'barrierearm' : '–' ?></td>
                                    <td>
                                        <form method="post" action="/admin/booking-selection/reserve">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                            <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                            <input type="hidden" name="locker_id" value="<?= (int) $locker['locker_id'] ?>">
                                            <button class="button button-secondary" type="submit" <?= $paymentRunning ? 'disabled' : '' ?>>Auswählen</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Nächster Buchungsschritt</h2>
            <p>Diese Testoberfläche endet bewusst bei der Reservierung. Sie erzeugt weder eine Zahlung noch eine verbindliche Buchung. Der spätere Produktivablauf darf die Buchung erst nach dem vorgesehenen Zahlungs- oder BuT-Prozess abschließen.</p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
