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
/** @var array{free:int,reserved:int,occupied:int,unavailable:int} $counts */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$groups = LockerGridRenderer::groups($lockerOverview, 'locker_id');
$statusLabel = static fn (string $status): string => match ($status) {
    'free' => 'Frei',
    'reserved' => 'Reserviert',
    'occupied' => 'Belegt',
    default => 'Nicht buchbar',
};
$statusClass = static fn (string $status): string => match ($status) {
    'free' => 'is-free',
    'reserved' => 'is-reserved',
    'occupied' => 'is-occupied',
    default => 'is-unavailable',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfachbelegung · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
        .locker-status-legend { display:flex; flex-wrap:wrap; gap:10px; }
        .locker-status-key { display:inline-flex; align-items:center; gap:7px; font-size:.86rem; color:#526078; }
        .locker-status-dot { width:12px; height:12px; border-radius:50%; border:1px solid rgba(23,32,51,.16); }
        .locker-status-dot.is-free, .locker-admin-cell.is-free, .locker-status-pill.is-free { background:#e8f7ed; border-color:#b9e2c8; color:#176238; }
        .locker-status-dot.is-reserved, .locker-admin-cell.is-reserved, .locker-status-pill.is-reserved { background:#fff5d9; border-color:#ead28b; color:#735700; }
        .locker-status-dot.is-occupied, .locker-admin-cell.is-occupied, .locker-status-pill.is-occupied { background:#fff0ef; border-color:#f2c4c0; color:#7e211c; }
        .locker-status-dot.is-unavailable, .locker-admin-cell.is-unavailable, .locker-status-pill.is-unavailable { background:#f1f3f6; border-color:#d9dee7; color:#667085; }
        .locker-management-group { display:grid; gap:14px; }
        .locker-management-group > header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-start; gap:14px; }
        .locker-management-group h2 { margin:3px 0 0; }
        .locker-admin-cell { min-width:150px; min-height:118px; display:grid; align-content:start; gap:5px; padding:10px; border:1px solid; border-radius:10px; }
        .locker-admin-cell code { font-weight:800; }
        .locker-admin-cell small { line-height:1.3; }
        .locker-admin-actions { display:flex; flex-wrap:wrap; gap:5px; margin-top:4px; }
        .locker-admin-actions form { margin:0; }
        .locker-admin-actions .button { padding:6px 9px; border-radius:8px; font-size:.76rem; }
        .locker-admin-actions .button-secondary { background:rgba(255,255,255,.75); }
        .locker-status-pill { display:inline-flex; width:max-content; padding:3px 8px; border:1px solid; border-radius:999px; font-size:.72rem; font-weight:800; }
        .locker-group-meta { display:flex; flex-wrap:wrap; gap:8px; }
        .locker-group-views { display:grid; gap:10px; }
        .locker-group-views > details { border:1px solid #e5e8ee; border-radius:12px; background:#fbfcfd; }
        .locker-group-views > details > summary { padding:12px 14px; cursor:pointer; font-weight:700; }
        .locker-group-view-body { padding:14px; border-top:1px solid #e5e8ee; }
        .locker-list-status { white-space:normal; min-width:170px; }
        .locker-list-person { white-space:normal; min-width:190px; }
        .locker-list-actions { min-width:220px; }
        .locker-overview-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
        .locker-overview-stat { padding:12px 14px; border:1px solid #e1e5eb; border-radius:11px; background:#fafbfc; }
        .locker-overview-stat strong { display:block; font-size:1.35rem; }
        @media (max-width: 760px) {
            .locker-overview-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .locker-admin-cell { min-width:132px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Schließfachbelegung</div>
    <div class="topbar-actions">
        <a href="/admin/recommendations">Empfehlungsvorschau</a>
        <a href="/admin/allocation-rules">Zuteilungsregeln</a>
        <a href="/">Dashboard</a>
    </div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Schließfachverwaltung</span>
        <h1>Belegung, Reservierung und Direktzuweisung</h1>
        <p>Alle Schrankgruppen eines Schuljahres mit Raster- und Listenansicht. Freie Fächer können reserviert oder ohne Zahlung direkt einem Schüler zugewiesen werden.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div>
            <span class="eyebrow">Ansicht und Aktion</span>
            <h2>Schuljahr und Schüler</h2>
        </div>
        <form method="get" action="/admin/booking-selection" class="grid">
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
        <p class="form-hint">Ohne Schülerauswahl dient die Seite als reine Belegungsübersicht. Bei Auswahl eines Schülers werden regelkonforme freie Fächer zur Direktzuweisung oder Reservierung freigeschaltet.</p>
        <div class="locker-status-legend" aria-label="Legende">
            <span class="locker-status-key"><span class="locker-status-dot is-free"></span>Frei</span>
            <span class="locker-status-key"><span class="locker-status-dot is-reserved"></span>Reserviert</span>
            <span class="locker-status-key"><span class="locker-status-dot is-occupied"></span>Belegt</span>
            <span class="locker-status-key"><span class="locker-status-dot is-unavailable"></span>Nicht buchbar</span>
        </div>
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
            <p class="form-hint"><strong>Direkt zuweisen</strong> erzeugt sofort eine aktive Buchung mit 0 € berechnetem Beitrag und Kennzeichnung als administrative Zuweisung. <strong>Reservieren</strong> nutzt die konfigurierte zeitlich begrenzte Reservierung.</p>
        </section>
    <?php endif; ?>

    <?php if ($selectedSchoolYearId !== null): ?>
        <?php if ($groups === []): ?>
            <section class="card"><p>Für dieses Schuljahr sind keine aktiven Schließfächer vorhanden.</p></section>
        <?php endif; ?>

        <?php foreach ($groups as $group): ?>
            <?php
            $groupLockers = [];
            $groupCounts = ['free' => 0, 'reserved' => 0, 'occupied' => 0, 'unavailable' => 0];
            foreach ($group['corpuses'] as $corpus) {
                foreach ($corpus as $locker) {
                    $groupLockers[] = $locker;
                    $groupStatus = (string) ($locker['availability_status'] ?? 'unavailable');
                    if (isset($groupCounts[$groupStatus])) {
                        ++$groupCounts[$groupStatus];
                    }
                }
            }
            usort($groupLockers, static function (array $left, array $right): int {
                return [(int) $left['_grid_corpus_position'], (int) $left['_grid_locker_position']]
                    <=> [(int) $right['_grid_corpus_position'], (int) $right['_grid_locker_position']];
            });
            $location = implode(' · ', array_values(array_filter([
                (string) $group['building_name'],
                (string) $group['floor_name'],
                (string) $group['area_name'],
            ], static fn (string $value): bool => $value !== '')));
            ?>
            <section class="card locker-management-group">
                <header>
                    <div>
                        <span class="eyebrow">Schrankgruppe</span>
                        <h2><?= $e((string) $group['group_code']) ?></h2>
                        <p class="form-hint"><?= $e($location) ?></p>
                    </div>
                    <div class="locker-group-meta">
                        <span class="locker-status-pill is-free"><?= $groupCounts['free'] ?> frei</span>
                        <span class="locker-status-pill is-reserved"><?= $groupCounts['reserved'] ?> reserviert</span>
                        <span class="locker-status-pill is-occupied"><?= $groupCounts['occupied'] ?> belegt</span>
                        <?php if ($groupCounts['unavailable'] > 0): ?><span class="locker-status-pill is-unavailable"><?= $groupCounts['unavailable'] ?> gesperrt</span><?php endif; ?>
                    </div>
                </header>

                <div class="locker-group-views">
                    <details open>
                        <summary>Rasteransicht</summary>
                        <div class="locker-group-view-body locker-grid-scroll">
                            <table class="locker-grid-table">
                                <thead><tr><th>Fach</th><?php foreach (array_keys($group['corpuses']) as $corpusPosition): ?><th>Korpus <?= str_pad((string) $corpusPosition, 2, '0', STR_PAD_LEFT) ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                <?php for ($position = 1; $position <= (int) $group['max_locker_position']; ++$position): ?>
                                    <tr>
                                        <th>Position <?= $position ?></th>
                                        <?php foreach ($group['corpuses'] as $corpus): ?>
                                            <?php $locker = $corpus[$position] ?? null; ?>
                                            <td>
                                                <?php if (!is_array($locker)): ?>
                                                    <span class="locker-grid-empty">–</span>
                                                <?php else: ?>
                                                    <?php
                                                    $lockerId = (int) $locker['_grid_id'];
                                                    $lockerStatus = (string) ($locker['availability_status'] ?? 'unavailable');
                                                    $canAct = $selectedStudentId !== null
                                                        && $selectionBlockedReason === null
                                                        && isset($eligibleLockerIds[$lockerId]);
                                                    ?>
                                                    <div class="locker-admin-cell <?= $statusClass($lockerStatus) ?>">
                                                        <div class="cluster">
                                                            <code><?= $e((string) $locker['_grid_short_name']) ?></code>
                                                            <?php if (isset($recommendedLockerIds[$lockerId])): ?><span class="badge">Empfohlen</span><?php endif; ?>
                                                        </div>
                                                        <span class="locker-status-pill <?= $statusClass($lockerStatus) ?>"><?= $statusLabel($lockerStatus) ?></span>

                                                        <?php if ($lockerStatus === 'occupied'): ?>
                                                            <small><strong><?= $e((string) ($locker['occupied_last_name'] ?? '')) ?>, <?= $e((string) ($locker['occupied_first_name'] ?? '')) ?></strong><br><?= $e((string) ($locker['occupied_class_name'] ?? '')) ?></small>
                                                        <?php elseif ($lockerStatus === 'reserved'): ?>
                                                            <small><strong><?= $e((string) ($locker['reserved_last_name'] ?? '')) ?>, <?= $e((string) ($locker['reserved_first_name'] ?? '')) ?></strong><br><?= $e((string) ($locker['reserved_class_name'] ?? '')) ?></small>
                                                            <small><?= (string) ($locker['reservation_status'] ?? '') === 'payment_running' ? 'Zahlung läuft' : 'bis ' . $e((string) ($locker['reservation_expires_at'] ?? '–')) ?></small>
                                                            <?php if ((string) ($locker['reservation_status'] ?? '') !== 'payment_running' && (int) ($locker['reservation_id'] ?? 0) > 0 && (int) ($locker['reserved_student_id'] ?? 0) > 0): ?>
                                                                <div class="locker-admin-actions">
                                                                    <form method="post" action="/admin/booking-selection/cancel">
                                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                                        <input type="hidden" name="student_id" value="<?= (int) $locker['reserved_student_id'] ?>">
                                                                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                                        <input type="hidden" name="reservation_id" value="<?= (int) $locker['reservation_id'] ?>">
                                                                        <button class="button button-secondary" type="submit">Freigeben</button>
                                                                    </form>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php elseif ($lockerStatus === 'free'): ?>
                                                            <?php if ($canAct): ?>
                                                                <small>regelkonform<?= isset($scores[$lockerId]) ? ' · Score ' . (int) $scores[$lockerId] : '' ?></small>
                                                                <div class="locker-admin-actions">
                                                                    <form method="post" action="/admin/booking-selection/assign">
                                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                                        <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                                                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                                        <input type="hidden" name="locker_id" value="<?= $lockerId ?>">
                                                                        <button class="button" type="submit">Zuweisen</button>
                                                                    </form>
                                                                    <form method="post" action="/admin/booking-selection/reserve">
                                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                                        <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                                                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                                        <input type="hidden" name="locker_id" value="<?= $lockerId ?>">
                                                                        <button class="button button-secondary" type="submit">Reservieren</button>
                                                                    </form>
                                                                </div>
                                                            <?php elseif ($selectedStudentId !== null): ?>
                                                                <small>Für den ausgewählten Schüler nicht auswählbar.</small>
                                                            <?php else: ?>
                                                                <small>Schüler oben auswählen, um zuzuweisen oder zu reservieren.</small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <small><?= (string) $locker['operating_status'] !== 'operational' ? $e((string) $locker['operating_status']) : 'nicht buchbar' ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <details>
                        <summary>Listenansicht</summary>
                        <div class="locker-group-view-body table-scroll">
                            <table class="data-table">
                                <thead><tr><th>Fach</th><th>Status</th><th>Schüler</th><th>Merkmale</th><th>Aktionen</th></tr></thead>
                                <tbody>
                                <?php foreach ($groupLockers as $locker): ?>
                                    <?php
                                    $lockerId = (int) $locker['_grid_id'];
                                    $lockerStatus = (string) ($locker['availability_status'] ?? 'unavailable');
                                    $canAct = $selectedStudentId !== null
                                        && $selectionBlockedReason === null
                                        && isset($eligibleLockerIds[$lockerId]);
                                    ?>
                                    <tr>
                                        <td><code><?= $e((string) $locker['_grid_short_name']) ?></code><br><small><?= $e((string) $locker['long_name']) ?></small></td>
                                        <td class="locker-list-status"><span class="locker-status-pill <?= $statusClass($lockerStatus) ?>"><?= $statusLabel($lockerStatus) ?></span><?php if (isset($recommendedLockerIds[$lockerId])): ?> <span class="badge">Empfohlen</span><?php endif; ?></td>
                                        <td class="locker-list-person">
                                            <?php if ($lockerStatus === 'occupied'): ?>
                                                <?= $e((string) ($locker['occupied_last_name'] ?? '')) ?>, <?= $e((string) ($locker['occupied_first_name'] ?? '')) ?><br><small><?= $e((string) ($locker['occupied_class_name'] ?? '')) ?></small>
                                            <?php elseif ($lockerStatus === 'reserved'): ?>
                                                <?= $e((string) ($locker['reserved_last_name'] ?? '')) ?>, <?= $e((string) ($locker['reserved_first_name'] ?? '')) ?><br><small><?= $e((string) ($locker['reserved_class_name'] ?? '')) ?></small>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td><?= (bool) $locker['barrier_friendly'] ? 'barrierearm' : '–' ?><?= isset($scores[$lockerId]) ? '<br><small>Score ' . (int) $scores[$lockerId] . '</small>' : '' ?></td>
                                        <td class="locker-list-actions">
                                            <?php if ($lockerStatus === 'free' && $canAct): ?>
                                                <div class="actions">
                                                    <form method="post" action="/admin/booking-selection/assign">
                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                        <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                        <input type="hidden" name="locker_id" value="<?= $lockerId ?>">
                                                        <button class="button" type="submit">Direkt zuweisen</button>
                                                    </form>
                                                    <form method="post" action="/admin/booking-selection/reserve">
                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                        <input type="hidden" name="student_id" value="<?= $selectedStudentId ?>">
                                                        <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                        <input type="hidden" name="locker_id" value="<?= $lockerId ?>">
                                                        <button class="button button-secondary" type="submit">Reservieren</button>
                                                    </form>
                                                </div>
                                            <?php elseif ($lockerStatus === 'reserved' && (string) ($locker['reservation_status'] ?? '') !== 'payment_running' && (int) ($locker['reservation_id'] ?? 0) > 0): ?>
                                                <form method="post" action="/admin/booking-selection/cancel">
                                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                    <input type="hidden" name="student_id" value="<?= (int) $locker['reserved_student_id'] ?>">
                                                    <input type="hidden" name="school_year_id" value="<?= $selectedSchoolYearId ?>">
                                                    <input type="hidden" name="reservation_id" value="<?= (int) $locker['reservation_id'] ?>">
                                                    <button class="button button-secondary" type="submit">Reservierung freigeben</button>
                                                </form>
                                            <?php elseif ($lockerStatus === 'reserved' && (string) ($locker['reservation_status'] ?? '') === 'payment_running'): ?>
                                                <small>Zahlungsvorgang läuft; Beenden nur über die Zahlungsverwaltung.</small>
                                            <?php elseif ($lockerStatus === 'free' && $selectedStudentId === null): ?>
                                                <small>Schüler auswählen.</small>
                                            <?php elseif ($lockerStatus === 'free'): ?>
                                                <small>Nicht regelkonform oder Schüler bereits gebucht.</small>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
