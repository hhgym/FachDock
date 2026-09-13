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
/** @var array<string, mixed>|null $currentBooking */
/** @var string|null $selectionBlockedReason */
/** @var list<array<string, mixed>> $lockerOverview */
/** @var array<int, bool> $eligibleLockerIds */
/** @var array<int, bool> $recommendedLockerIds */
/** @var array<int, int> $scores */
/** @var array{free:int,reserved:int,occupied:int,issue:int,unavailable:int} $counts */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formFields = static function (array $fields) use ($e): string {
    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $e((string) $name) . '" value="' . $e((string) $value) . '">';
    }

    return $html;
};
$lockerActions = static function (array $locker, string $view, string $status) use (
    $csrfToken,
    $selectedStudentId,
    $selectedSchoolYearId,
    $selectionBlockedReason,
    $eligibleLockerIds,
    $formFields,
): string {
    $lockerId = (int) ($locker['_grid_id'] ?? 0);
    if ($selectedSchoolYearId === null || $lockerId < 1) {
        return '';
    }

    if ($status === 'free') {
        if ($selectedStudentId === null) {
            return '';
        }
        if ($selectionBlockedReason !== null || !isset($eligibleLockerIds[$lockerId])) {
            return $view === 'grid'
                ? '<small class="locker-status-action-note">Nicht auswählbar</small>'
                : '<small>Nicht regelkonform oder bereits gebucht.</small>';
        }

        $fields = $formFields([
            '_csrf' => $csrfToken,
            'student_id' => $selectedStudentId,
            'school_year_id' => $selectedSchoolYearId,
            'locker_id' => $lockerId,
        ]);
        $assignLabel = $view === 'grid' ? 'Zuweisen' : 'Direkt zuweisen';

        return '<form method="post" action="/admin/lockers/assign">' . $fields
            . '<button class="button" type="submit">' . $assignLabel . '</button></form>'
            . '<form method="post" action="/admin/lockers/reserve">' . $fields
            . '<button class="button button-secondary" type="submit">Reservieren</button></form>';
    }

    $reservationId = (int) ($locker['reservation_id'] ?? 0);
    $reservedStudentId = (int) ($locker['reserved_student_id'] ?? 0);
    if ($reservationId < 1 || $reservedStudentId < 1) {
        return '';
    }
    if ((string) ($locker['reservation_status'] ?? '') === 'payment_running') {
        return $view === 'list'
            ? '<small>Zahlungsvorgang läuft; Beenden nur über die Zahlungsverwaltung.</small>'
            : '';
    }

    $fields = $formFields([
        '_csrf' => $csrfToken,
        'student_id' => $reservedStudentId,
        'school_year_id' => $selectedSchoolYearId,
        'reservation_id' => $reservationId,
    ]);

    return '<form method="post" action="/admin/lockers/reservation/cancel">' . $fields
        . '<button class="button button-secondary" type="submit">'
        . ($view === 'grid' ? 'Freigeben' : 'Reservierung freigeben') . '</button></form>';
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfächer · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong> · Schließfächer</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Schließfachverwaltung</span>
        <h1>Belegung und Zuweisung</h1>
        <p>Schrankgruppen im Raster oder als Liste prüfen, Reservierungen verwalten und freie Fächer direkt zuweisen.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div>
            <span class="eyebrow">Ansicht und Aktion</span>
            <h2>Schuljahr und Schüler</h2>
        </div>
        <form method="get" action="/admin/lockers" class="grid">
            <label>Schuljahr
                <select name="school_year_id" required>
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= $e((string) $year['label']) ?><?= (string) $year['status'] === 'current' ? ' · aktuell' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Schüler für Zuweisung/Reservierung
                <select name="student_id">
                    <option value="">Nur Belegung anzeigen</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                            <?= $e((string) $student['class_name']) ?> · <?= $e((string) $student['last_name']) ?>, <?= $e((string) $student['first_name']) ?> · <?= $e((string) $student['matrikelnummer']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" type="submit">Ansicht laden</button>
        </form>
        <p class="form-hint">Ohne Schülerauswahl dient die Seite als Belegungsübersicht. Mit Schülerauswahl werden regelkonforme freie Fächer zur Zuweisung oder Reservierung freigeschaltet.</p>
        <?= LockerGridRenderer::statusLegend() ?>
    </section>

    <?php if ($selectedSchoolYearId !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow">Gesamtstatus</span>
                    <h2>Schließfächer im gewählten Schuljahr</h2>
                </div>
                <span class="badge"><?= count($lockerOverview) ?> Fächer</span>
            </div>
            <div class="locker-overview-stats">
                <div class="locker-overview-stat"><small>Frei</small><strong><?= (int) $counts['free'] ?></strong></div>
                <div class="locker-overview-stat"><small>Reserviert</small><strong><?= (int) $counts['reserved'] ?></strong></div>
                <div class="locker-overview-stat"><small>Belegt</small><strong><?= (int) $counts['occupied'] ?></strong></div>
                <div class="locker-overview-stat"><small>Defekt / Meldung</small><strong><?= (int) $counts['issue'] ?></strong></div>
                <div class="locker-overview-stat"><small>Nicht buchbar</small><strong><?= (int) $counts['unavailable'] ?></strong></div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($selectedStudent !== null && $projectedGrade !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow">Ausgewählter Schüler</span>
                    <h2><?= $e((string) $selectedStudent['first_name']) ?> <?= $e((string) $selectedStudent['last_name']) ?></h2>
                    <p class="form-hint"><?= $e((string) $selectedStudent['class_name']) ?> · Zielklassenstufe <?= $projectedGrade ?></p>
                </div>
                <span class="badge"><?= count($eligibleLockerIds) ?> regelkonforme freie Fächer</span>
            </div>
            <?php if ($currentBooking !== null): ?>
                <div class="alert alert-neutral">
                    Bereits zugewiesen: <strong><code><?= $e((string) $currentBooking['short_name']) ?></code></strong>
                    · <?= $e((string) $currentBooking['long_name']) ?>. Eine weitere Zuweisung ist deshalb gesperrt.
                </div>
            <?php elseif ($activeReservation !== null): ?>
                <div class="alert alert-neutral">
                    Aktive Reservierung: <strong><code><?= $e((string) $activeReservation['short_name']) ?></code></strong>
                    · <?= $e((string) $activeReservation['long_name']) ?>
                    <?php if ((string) $activeReservation['status'] === 'payment_running'): ?>
                        · Zahlung läuft bis <?= $e((string) ($activeReservation['payment_grace_expires_at'] ?? '–')) ?>
                    <?php else: ?>
                        · reserviert bis <?= $e((string) $activeReservation['expires_at']) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($selectionBlockedReason !== null): ?>
                <div class="alert alert-neutral"><?= $e($selectionBlockedReason) ?></div>
            <?php endif; ?>
            <p class="form-hint"><strong>Direkt zuweisen</strong> erzeugt sofort eine aktive Buchung ohne Zahlung. <strong>Reservieren</strong> nutzt die konfigurierte zeitlich begrenzte Reservierung.</p>
        </section>
    <?php endif; ?>

    <?php if ($selectedSchoolYearId !== null): ?>
        <section class="card stack locker-overview-card">
            <div>
                <span class="eyebrow">Schrankgruppen</span>
                <h2>Raster- und Listenansicht</h2>
                <?php if ($selectedStudentId === null): ?>
                    <p class="form-hint locker-grid-selection-hint">Schüler oben auswählen, um freie Fächer zuzuweisen oder zu reservieren.</p>
                <?php endif; ?>
            </div>
            <?= LockerGridRenderer::statusViews(
                $lockerOverview,
                $lockerActions,
                $recommendedLockerIds,
                $scores,
                'locker_id',
            ) ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
