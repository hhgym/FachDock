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
/** @var array<string, mixed> $locationContext */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formFields = static function (array $fields) use ($e): string {
    $html = '';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . $e((string) $name) . '" value="' . $e((string) $value) . '">';
    }

    return $html;
};
$floors = is_array($locationContext['floors'] ?? null) ? $locationContext['floors'] : [];
$floorPlans = is_array($locationContext['floor_plans'] ?? null) ? $locationContext['floor_plans'] : [];
$selectedFloorId = is_int($locationContext['selected_floor_id'] ?? null)
    ? $locationContext['selected_floor_id']
    : null;
$selectedPlanId = is_int($locationContext['selected_plan_id'] ?? null)
    ? $locationContext['selected_plan_id']
    : null;
$selectedGroupId = is_int($locationContext['selected_group_id'] ?? null)
    ? $locationContext['selected_group_id']
    : null;
$plan = is_array($locationContext['plan'] ?? null) ? $locationContext['plan'] : null;
$selectedGroup = is_array($locationContext['selected_group'] ?? null) ? $locationContext['selected_group'] : null;
$selectedGroupOverview = is_array($locationContext['selected_group_overview'] ?? null)
    ? $locationContext['selected_group_overview']
    : [];
$selectionContextFields = [];
if ($selectedFloorId !== null) {
    $selectionContextFields['floor_id'] = $selectedFloorId;
}
if ($selectedPlanId !== null) {
    $selectionContextFields['plan_id'] = $selectedPlanId;
}
if ($selectedGroupId !== null) {
    $selectionContextFields['group_id'] = $selectedGroupId;
}
$lockerActions = static function (array $locker, string $view, string $status) use (
    $csrfToken,
    $selectedStudentId,
    $selectedSchoolYearId,
    $selectionBlockedReason,
    $eligibleLockerIds,
    $selectionContextFields,
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

        $fields = $formFields(array_merge([
            '_csrf' => $csrfToken,
            'student_id' => $selectedStudentId,
            'school_year_id' => $selectedSchoolYearId,
            'locker_id' => $lockerId,
        ], $selectionContextFields));
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

    $fields = $formFields(array_merge([
        '_csrf' => $csrfToken,
        'student_id' => $reservedStudentId,
        'school_year_id' => $selectedSchoolYearId,
        'reservation_id' => $reservationId,
    ], $selectionContextFields));

    return '<form method="post" action="/admin/lockers/reservation/cancel">' . $fields
        . '<button class="button button-secondary" type="submit">'
        . ($view === 'grid' ? 'Freigeben' : 'Reservierung freigeben') . '</button></form>';
};
$groupUrl = static function (int $groupId) use (
    $selectedSchoolYearId,
    $selectedStudentId,
    $selectedFloorId,
    $selectedPlanId,
): string {
    $query = ['school_year_id' => $selectedSchoolYearId];
    if ($selectedStudentId !== null) {
        $query['student_id'] = $selectedStudentId;
    }
    if ($selectedFloorId !== null) {
        $query['floor_id'] = $selectedFloorId;
    }
    if ($selectedPlanId !== null) {
        $query['plan_id'] = $selectedPlanId;
    }
    $query['group_id'] = $groupId;

    return '/admin/lockers?' . http_build_query($query) . '#locker-group';
};
$groupMarkerClass = static function (array $group): string {
    $groupCounts = is_array($group['admin_counts'] ?? null) ? $group['admin_counts'] : [];
    if ((int) ($groupCounts['issue'] ?? 0) > 0) {
        return 'warning';
    }

    return (int) ($groupCounts['free'] ?? 0) > 0 ? 'free' : 'full';
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfächer · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/locker-grid.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong> · Schließfächer</header>
<main class="shell stack floorplan-page locker-admin-floorplan-page">
    <header class="hero">
        <span class="eyebrow">Schließfachverwaltung</span>
        <h1>Belegung und Zuweisung</h1>
        <p>Etage und Schrankgruppe über den Lageplan auswählen, Belegung prüfen und freie Fächer direkt zuweisen oder reservieren.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div>
            <span class="eyebrow">Grundauswahl</span>
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
        <?php if ($floors === []): ?>
            <section class="card empty-state">
                <h2>Noch keine Lagepläne eingerichtet</h2>
                <p>Für die Schließfachverwaltung ist derzeit kein aktiver Etagenplan vorhanden.</p>
            </section>
        <?php else: ?>
            <section class="card stack floorplan-filters">
                <div>
                    <span class="eyebrow">Schritt 1 · Etage</span>
                    <h2>Etage auswählen</h2>
                    <p class="form-hint">Erst nach der Etagenwahl wird der zugehörige Lageplan angezeigt.</p>
                </div>
                <form method="get" action="/admin/lockers" class="compact-form">
                    <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                    <?php if ($selectedStudentId !== null): ?><input type="hidden" name="student_id" value="<?= (int) $selectedStudentId ?>"><?php endif; ?>
                    <label>Gebäude / Etage
                        <select name="floor_id" required onchange="this.form.submit()">
                            <option value="" <?= $selectedFloorId === null ? 'selected' : '' ?>>Bitte Etage auswählen</option>
                            <?php foreach ($floors as $floor): ?>
                                <option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $selectedFloorId ? 'selected' : '' ?>>
                                    <?= $e((string) $floor['building_name']) ?> · <?= $e((string) $floor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button class="button" type="submit">Etage öffnen</button></noscript>
                </form>
            </section>

            <?php if ($selectedFloorId !== null): ?>
                <?php if ($floorPlans === [] || $plan === null): ?>
                    <section class="card empty-state">
                        <h2>Kein Lageplan vorhanden</h2>
                        <p>Für die ausgewählte Etage ist kein aktiver Lageplan verfügbar.</p>
                    </section>
                <?php else: ?>
                    <?php if (count($floorPlans) > 1): ?>
                        <section class="card floorplan-filters">
                            <form method="get" action="/admin/lockers" class="compact-form">
                                <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                                <?php if ($selectedStudentId !== null): ?><input type="hidden" name="student_id" value="<?= (int) $selectedStudentId ?>"><?php endif; ?>
                                <input type="hidden" name="floor_id" value="<?= (int) $selectedFloorId ?>">
                                <label>Lageplan
                                    <select name="plan_id" onchange="this.form.submit()">
                                        <?php foreach ($floorPlans as $item): ?>
                                            <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $selectedPlanId ? 'selected' : '' ?>><?= $e((string) $item['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <noscript><button class="button" type="submit">Plan öffnen</button></noscript>
                            </form>
                        </section>
                    <?php endif; ?>

                    <?php
                    $placedGroups = array_values(array_filter(
                        $plan['groups'],
                        static fn (array $group): bool => (bool) ($group['placed'] ?? false),
                    ));
                    ?>
                    <section class="floorplan-layout locker-admin-floorplan">
                        <div class="card floorplan-stage-card">
                            <div class="floorplan-toolbar">
                                <div>
                                    <span class="eyebrow">Schritt 2 · Schrankgruppe</span>
                                    <strong><?= $e((string) $plan['building_name']) ?> · <?= $e((string) $plan['floor_name']) ?></strong>
                                    <span><?= $e((string) $plan['title']) ?></span>
                                </div>
                                <div class="button-row" aria-label="Zoom">
                                    <button class="button button-secondary" type="button" data-admin-locker-map-zoom-out>−</button>
                                    <output data-admin-locker-map-zoom-label>100 %</output>
                                    <button class="button button-secondary" type="button" data-admin-locker-map-zoom-in>+</button>
                                    <button class="button button-secondary" type="button" data-admin-locker-map-zoom-reset>100 %</button>
                                </div>
                            </div>
                            <p class="form-hint">Wählen Sie eine Schrankgruppe im Lageplan oder in der Gruppenliste. Erst danach erscheinen Raster- und Listenansicht der einzelnen Fächer.</p>
                            <div class="floorplan-legend" aria-label="Legende">
                                <span><i class="floorplan-dot floorplan-dot-free"></i> freie Fächer vorhanden</span>
                                <span><i class="floorplan-dot floorplan-dot-full"></i> keine freien Fächer</span>
                                <span><i class="floorplan-dot floorplan-dot-warning"></i> Defekt / Schadensmeldung</span>
                            </div>
                            <div class="floorplan-scroll" data-admin-locker-map-scroll>
                                <div class="floorplan-canvas" data-admin-locker-map-canvas>
                                    <img src="<?= $e((string) $plan['image_url']) ?>" alt="<?= $e((string) $plan['title']) ?>">
                                    <?php foreach ($placedGroups as $group): ?>
                                        <?php
                                        $markerClass = $groupMarkerClass($group);
                                        $style = sprintf(
                                            'left:%.3f%%;top:%.3f%%;width:%.3f%%;height:%.3f%%',
                                            (float) $group['x_percent'],
                                            (float) $group['y_percent'],
                                            (float) $group['width_percent'],
                                            (float) $group['height_percent'],
                                        );
                                        $groupCounts = is_array($group['admin_counts'] ?? null) ? $group['admin_counts'] : [];
                                        ?>
                                        <a class="floorplan-marker floorplan-marker-<?= $e($markerClass) ?><?= (int) $group['id'] === $selectedGroupId ? ' locker-admin-group-selected' : '' ?>"
                                           style="<?= $e($style) ?>"
                                           href="<?= $e($groupUrl((int) $group['id'])) ?>"
                                           aria-label="Schrankgruppe <?= $e((string) $group['code']) ?> auswählen">
                                            <span><?= $e((string) $group['code']) ?></span>
                                            <small><?= (int) ($groupCounts['free'] ?? 0) ?> frei</small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <aside class="floorplan-sidebar">
                            <section class="card stack">
                                <h2>Schrankgruppen</h2>
                                <p class="form-hint">Auch noch nicht auf dem Lageplan positionierte Gruppen können hier ausgewählt werden.</p>
                                <div class="floorplan-group-summary">
                                    <?php foreach ($plan['groups'] as $group): ?>
                                        <?php
                                        $markerClass = $groupMarkerClass($group);
                                        $groupCounts = is_array($group['admin_counts'] ?? null) ? $group['admin_counts'] : [];
                                        ?>
                                        <a class="floorplan-group-row<?= (int) $group['id'] === $selectedGroupId ? ' locker-admin-group-selected' : '' ?>" href="<?= $e($groupUrl((int) $group['id'])) ?>">
                                            <span class="floorplan-dot floorplan-dot-<?= $e($markerClass) ?>"></span>
                                            <span><strong><?= $e((string) $group['code']) ?></strong><small><?= $e((string) $group['area_name']) ?></small></span>
                                            <span><?= (int) ($groupCounts['free'] ?? 0) ?> frei</span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </aside>
                    </section>

                    <?php if ($selectedGroup !== null): ?>
                        <?php $selectedGroupCounts = is_array($selectedGroup['admin_counts'] ?? null) ? $selectedGroup['admin_counts'] : []; ?>
                        <section id="locker-group" class="card stack locker-overview-card">
                            <div class="school-year-heading">
                                <div>
                                    <span class="eyebrow">Schritt 3 · Schließfach</span>
                                    <h2>Schrankgruppe <?= $e((string) $selectedGroup['code']) ?> · <?= $e((string) $selectedGroup['name']) ?></h2>
                                    <p class="form-hint"><?= $e((string) $selectedGroup['area_name']) ?> · Raster- oder Listenansicht wählen.</p>
                                </div>
                                <div class="locker-group-meta">
                                    <span class="badge"><?= (int) ($selectedGroupCounts['free'] ?? 0) ?> frei</span>
                                    <span class="badge"><?= (int) ($selectedGroupCounts['reserved'] ?? 0) ?> reserviert</span>
                                    <span class="badge"><?= (int) ($selectedGroupCounts['occupied'] ?? 0) ?> belegt</span>
                                </div>
                            </div>
                            <?php if ($selectedStudentId === null): ?>
                                <p class="form-hint locker-grid-selection-hint">Schüler oben auswählen, um freie Fächer zuzuweisen oder zu reservieren.</p>
                            <?php endif; ?>
                            <?= LockerGridRenderer::statusViews(
                                $selectedGroupOverview,
                                $lockerActions,
                                $recommendedLockerIds,
                                $scores,
                                'locker_id',
                            ) ?>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(() => {
    const canvas = document.querySelector('[data-admin-locker-map-canvas]');
    if (!canvas) return;

    let zoom = 1;
    const label = document.querySelector('[data-admin-locker-map-zoom-label]');
    const scroll = document.querySelector('[data-admin-locker-map-scroll]');
    const applyZoom = () => {
        canvas.style.transformOrigin = 'top left';
        canvas.style.transform = `scale(${zoom})`;
        if (scroll) scroll.style.minHeight = `${canvas.offsetHeight * zoom}px`;
        if (label) label.textContent = `${Math.round(zoom * 100)} %`;
    };
    document.querySelector('[data-admin-locker-map-zoom-in]')?.addEventListener('click', () => {
        zoom = Math.min(3, zoom + .25);
        applyZoom();
    });
    document.querySelector('[data-admin-locker-map-zoom-out]')?.addEventListener('click', () => {
        zoom = Math.max(.5, zoom - .25);
        applyZoom();
    });
    document.querySelector('[data-admin-locker-map-zoom-reset]')?.addEventListener('click', () => {
        zoom = 1;
        applyZoom();
    });
})();
</script>
</body>
</html>