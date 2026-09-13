<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;
use FachDock\View\LockerGridRenderer;

/** @var string $appName */
/** @var AuthenticatedParent $parent */
/** @var array{id:int,first_name:string,last_name:string,class_name:string,grade:int} $child */
/** @var list<array{id:int,label:string,starts_on:string,ends_on:string,annual_fee_cents:int}> $schoolYears */
/** @var int|null $selectedSchoolYearId */
/** @var array<string,mixed>|null $selection */
/** @var string $csrfToken */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$active = $selection !== null && is_array($selection['active_reservation'] ?? null)
    ? $selection['active_reservation']
    : null;
$paymentRunning = $active !== null && (string) ($active['status'] ?? '') === 'payment_running';
$plan = $selection !== null && is_array($selection['plan'] ?? null) ? $selection['plan'] : null;
$floors = $selection !== null && is_array($selection['floors'] ?? null) ? $selection['floors'] : [];
$floorPlans = $selection !== null && is_array($selection['floor_plans'] ?? null) ? $selection['floor_plans'] : [];
$selectedFloorId = $selection !== null && is_int($selection['selected_floor_id'] ?? null)
    ? $selection['selected_floor_id']
    : null;
$selectedPlanId = $selection !== null && is_int($selection['selected_plan_id'] ?? null)
    ? $selection['selected_plan_id']
    : null;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfach auf Lageplan auswählen · <?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/locker-grid.css">
</head>
<body>
<header class="topbar">
    <div><strong><?= $e($appName) ?></strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent">Übersicht</a>
        <a href="/parent/bookings">Meine Buchungen</a>
        <a href="/parent/booking?student_id=<?= (int) $child['id'] ?><?= $selectedSchoolYearId !== null ? '&amp;school_year_id=' . (int) $selectedSchoolYearId : '' ?>">Listenansicht</a>
        <span><?= $e($parent->displayName()) ?></span>
    </div>
</header>

<main class="shell stack floorplan-page">
    <header class="hero">
        <span class="eyebrow">Buchung per Lageplan</span>
        <h1><?= $e($child['first_name'] . ' ' . $child['last_name']) ?></h1>
        <p>Klasse <?= $e($child['class_name']) ?>. Wählen Sie zuerst Schuljahr und Etage. Anschließend wählen Sie im Lageplan eine Schrankgruppe und danach das gewünschte Schließfach.</p>
    </header>

    <section class="card stack">
        <h2>Schuljahr</h2>
        <?php if ($schoolYears === []): ?>
            <p>Derzeit ist kein Schuljahr für Neubuchungen geöffnet.</p>
        <?php else: ?>
            <form method="get" action="/parent/booking/map" class="compact-form">
                <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                <label>Schuljahr
                    <select name="school_year_id" required onchange="this.form.submit()">
                        <option value="">Bitte wählen</option>
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>>
                                <?= $e($year['label']) ?> · <?= $e($money((int) $year['annual_fee_cents'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button class="button" type="submit">Weiter</button></noscript>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($selection !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <h2><?= $e($selection['school_year']['label']) ?> · Zielklassenstufe <?= (int) $selection['projected_grade'] ?></h2>
                    <p class="form-hint">Es werden nur Schließfächer zur Auswahl freigegeben, die aktuell frei, technisch buchbar und nach den Zuteilungsregeln zulässig sind.</p>
                </div>
                <span class="badge"><?= count($selection['available']) ?> buchbar</span>
            </div>

            <?php if ($active !== null): ?>
                <div class="alert stack">
                    <strong>Aktuell reserviert: <?= $e($active['short_name'] ?? '') ?></strong>
                    <div><?= $e($active['long_name'] ?? '') ?></div>
                    <?php if ($paymentRunning): ?>
                        <p>Für diese Reservierung läuft bereits ein Zahlungsvorgang. Eine andere Auswahl ist deshalb gesperrt.</p>
                    <?php else: ?>
                        <p>Die Reservierung kann noch geändert werden. Eine neue Auswahl ersetzt die bisherige Reservierung automatisch.</p>
                    <?php endif; ?>
                    <a class="button" href="/parent/booking?student_id=<?= (int) $child['id'] ?>&amp;school_year_id=<?= (int) $selectedSchoolYearId ?>">Weiter zur Buchung und Zahlung</a>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($floors === []): ?>
            <section class="card empty-state">
                <h2>Noch keine Lagepläne eingerichtet</h2>
                <p>Die Schule hat für die Schließfachbereiche noch keine Lagepläne hinterlegt. Nutzen Sie solange die Listenansicht.</p>
                <a class="button" href="/parent/booking?student_id=<?= (int) $child['id'] ?>&amp;school_year_id=<?= (int) $selectedSchoolYearId ?>">Zur Listenansicht</a>
            </section>
        <?php else: ?>
            <section class="card floorplan-filters stack">
                <div>
                    <span class="eyebrow">Schritt 1</span>
                    <h2>Etage auswählen</h2>
                    <p class="form-hint">Erst nach der Auswahl einer Etage wird der zugehörige Lageplan angezeigt.</p>
                </div>
                <form method="get" action="/parent/booking/map" class="form-grid floorplan-filter-form">
                    <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                    <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                    <label>Gebäude / Etage
                        <select name="floor_id" required onchange="this.form.submit()">
                            <option value="" <?= $selectedFloorId === null ? 'selected' : '' ?>>Bitte Etage auswählen</option>
                            <?php foreach ($floors as $floor): ?>
                                <option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $selectedFloorId ? 'selected' : '' ?>>
                                    <?= $e($floor['building_name']) ?> · <?= $e($floor['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($selectedFloorId !== null && $floorPlans !== []): ?>
                        <label>Plan
                            <select name="plan_id" onchange="this.form.submit()">
                                <?php foreach ($floorPlans as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $selectedPlanId ? 'selected' : '' ?>><?= $e($item['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <noscript><button class="button" type="submit">Lageplan anzeigen</button></noscript>
                </form>
            </section>

            <?php if ($selectedFloorId === null): ?>
                <section class="card empty-state">
                    <h2>Bitte zuerst eine Etage auswählen</h2>
                    <p>Danach erscheint der Lageplan. Wählen Sie dort eine Schrankgruppe aus, um deren Schließfächer im Raster zu sehen.</p>
                </section>
            <?php elseif ($plan === null): ?>
                <section class="card empty-state"><h2>Kein Lageplan vorhanden</h2><p>Für diese Etage ist kein aktiver Lageplan verfügbar.</p></section>
            <?php else: ?>
                <?php
                $placedGroups = array_values(array_filter(
                    $plan['groups'],
                    static fn (array $group): bool => (bool) $group['placed'],
                ));
                ?>
                <section class="card stack">
                    <div>
                        <span class="eyebrow">Schritt 2</span>
                        <h2>Schrankgruppe auswählen</h2>
                        <p class="form-hint">Wählen Sie eine Schrankgruppe direkt im Lageplan oder über die Gruppenliste. Erst danach wird das Fachraster geöffnet.</p>
                    </div>
                </section>

                <section class="floorplan-layout">
                    <div class="card floorplan-stage-card">
                        <div class="floorplan-toolbar">
                            <div>
                                <strong><?= $e($plan['building_name']) ?> · <?= $e($plan['floor_name']) ?></strong>
                                <span><?= $e($plan['title']) ?></span>
                            </div>
                            <div class="button-row" aria-label="Zoom">
                                <button class="button button-secondary" type="button" data-booking-map-zoom-out>−</button>
                                <output data-booking-map-zoom-label>100 %</output>
                                <button class="button button-secondary" type="button" data-booking-map-zoom-in>+</button>
                                <button class="button button-secondary" type="button" data-booking-map-zoom-reset>100 %</button>
                            </div>
                        </div>
                        <div class="floorplan-legend" aria-label="Legende">
                            <span><i class="floorplan-dot floorplan-dot-free"></i> auswählbar</span>
                            <span><i class="floorplan-dot floorplan-dot-full"></i> belegt/reserviert</span>
                            <span><i class="floorplan-dot floorplan-dot-warning"></i> Regel/Technik verhindert Auswahl</span>
                        </div>
                        <div class="floorplan-scroll" data-booking-map-scroll>
                            <div class="floorplan-canvas" data-booking-map-canvas>
                                <img src="<?= $e($plan['image_url']) ?>" alt="<?= $e($plan['title']) ?>">
                                <?php foreach ($placedGroups as $group): ?>
                                    <?php
                                    $marker = (string) $group['booking_marker_status'];
                                    $markerClass = $marker === 'warning' ? 'warning' : ($marker === 'full' ? 'full' : 'free');
                                    $style = sprintf(
                                        'left:%.3f%%;top:%.3f%%;width:%.3f%%;height:%.3f%%',
                                        (float) $group['x_percent'],
                                        (float) $group['y_percent'],
                                        (float) $group['width_percent'],
                                        (float) $group['height_percent'],
                                    );
                                    ?>
                                    <button type="button"
                                            class="floorplan-marker floorplan-marker-<?= $e($markerClass) ?>"
                                            style="<?= $e($style) ?>"
                                            data-booking-map-group="<?= (int) $group['id'] ?>"
                                            aria-label="Gruppe <?= $e($group['code']) ?>, <?= (int) $group['selectable_count'] ?> auswählbar">
                                        <span><?= $marker === 'selected' ? '✓ ' : '' ?><?= $e($group['code']) ?></span>
                                        <small><?= (int) $group['selectable_count'] ?> frei</small>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <aside class="floorplan-sidebar">
                        <section class="card stack">
                            <h2>Schrankgruppen</h2>
                            <p class="form-hint">Auch noch nicht auf dem Plan positionierte Gruppen bleiben hier erreichbar.</p>
                            <div class="floorplan-group-summary">
                                <?php foreach ($plan['groups'] as $group): ?>
                                    <?php
                                    $marker = (string) $group['booking_marker_status'];
                                    $dotClass = $marker === 'warning' ? 'warning' : ($marker === 'full' ? 'full' : 'free');
                                    ?>
                                    <button type="button" class="floorplan-group-row" data-booking-map-group="<?= (int) $group['id'] ?>">
                                        <span class="floorplan-dot floorplan-dot-<?= $e($dotClass) ?>"></span>
                                        <span><strong><?= $marker === 'selected' ? '✓ ' : '' ?><?= $e($group['code']) ?></strong><small><?= $e($group['area_name']) ?></small></span>
                                        <span><?= (int) $group['selectable_count'] ?> frei</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </aside>
                </section>

                <?php foreach ($plan['groups'] as $group): ?>
                    <template data-booking-map-template="<?= (int) $group['id'] ?>">
                        <div class="stack">
                            <div>
                                <span class="eyebrow">Schritt 3 · Schrankgruppe <?= $e($group['code']) ?></span>
                                <h2>Schließfach auswählen</h2>
                                <p><?= $e($group['name']) ?> · <?= $e($group['area_name']) ?> · <?= (int) $group['selectable_count'] ?> auswählbare Fächer</p>
                            </div>
                            <div class="locker-parent-grid-legend" aria-label="Verfügbarkeit">
                                <span><i class="locker-parent-grid-dot is-available"></i> verfügbar</span>
                                <span><i class="locker-parent-grid-dot is-unavailable"></i> nicht verfügbar</span>
                            </div>
                            <?php $gridGroups = LockerGridRenderer::groups($group['lockers'], 'id'); ?>
                            <?php foreach ($gridGroups as $gridGroup): ?>
                                <div class="locker-grid-scroll">
                                    <table class="locker-grid-table locker-parent-availability-grid">
                                        <thead>
                                        <tr>
                                            <th>Fach</th>
                                            <?php foreach (array_keys($gridGroup['corpuses']) as $corpusPosition): ?>
                                                <th>Korpus <?= str_pad((string) $corpusPosition, 2, '0', STR_PAD_LEFT) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php for ($position = 1; $position <= (int) $gridGroup['max_locker_position']; ++$position): ?>
                                            <tr>
                                                <th>Position <?= $position ?></th>
                                                <?php foreach ($gridGroup['corpuses'] as $corpus): ?>
                                                    <?php $locker = $corpus[$position] ?? null; ?>
                                                    <td>
                                                        <?php if (!is_array($locker)): ?>
                                                            <span class="locker-grid-empty" aria-label="kein Schließfach">–</span>
                                                        <?php else: ?>
                                                            <?php
                                                            $bookingStatus = (string) ($locker['booking_status'] ?? 'unavailable');
                                                            $selected = $bookingStatus === 'selected';
                                                            $available = $selected || $bookingStatus === 'selectable';
                                                            $availabilityClass = $available ? 'is-available' : 'is-unavailable';
                                                            $availabilityLabel = $selected
                                                                ? 'Ausgewählt'
                                                                : ($available ? 'Verfügbar' : 'Nicht verfügbar');
                                                            ?>
                                                            <?php if ($available && !$selected && (bool) ($locker['can_select'] ?? false)): ?>
                                                                <form class="locker-parent-availability-form" method="post" action="/parent/booking/reserve">
                                                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                                    <input type="hidden" name="student_id" value="<?= (int) $child['id'] ?>">
                                                                    <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                                                                    <input type="hidden" name="locker_id" value="<?= (int) $locker['_grid_id'] ?>">
                                                                    <button class="locker-parent-availability-cell <?= $e($availabilityClass) ?>" type="submit" aria-label="<?= $e($locker['_grid_short_name']) ?>, verfügbar, auswählen">
                                                                        <strong><?= $e($locker['_grid_short_name']) ?></strong>
                                                                        <small><?= $e($availabilityLabel) ?></small>
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <div class="locker-parent-availability-cell <?= $e($availabilityClass) ?>" aria-label="<?= $e($locker['_grid_short_name']) ?>, <?= $e(mb_strtolower($availabilityLabel)) ?>">
                                                                    <strong><?= $selected ? '✓ ' : '' ?><?= $e($locker['_grid_short_name']) ?></strong>
                                                                    <small><?= $e($availabilityLabel) ?></small>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($paymentRunning): ?>
                                <p class="form-hint">Die verfügbaren Fächer bleiben sichtbar. Eine neue Auswahl ist während des laufenden Zahlungsvorgangs jedoch gesperrt.</p>
                            <?php endif; ?>
                        </div>
                    </template>
                <?php endforeach; ?>

                <dialog class="floorplan-dialog" data-booking-map-dialog>
                    <form method="dialog"><button class="dialog-close" aria-label="Schließen">×</button></form>
                    <div data-booking-map-dialog-content></div>
                </dialog>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>

<script>
(() => {
    const canvas = document.querySelector('[data-booking-map-canvas]');
    const dialog = document.querySelector('[data-booking-map-dialog]');
    if (!canvas || !dialog) return;

    let zoom = 1;
    const label = document.querySelector('[data-booking-map-zoom-label]');
    const applyZoom = () => {
        canvas.style.transformOrigin = 'top left';
        canvas.style.transform = `scale(${zoom})`;
        const scroll = document.querySelector('[data-booking-map-scroll]');
        if (scroll) scroll.style.minHeight = `${canvas.offsetHeight * zoom}px`;
        if (label) label.textContent = `${Math.round(zoom * 100)} %`;
    };
    document.querySelector('[data-booking-map-zoom-in]')?.addEventListener('click', () => { zoom = Math.min(3, zoom + .25); applyZoom(); });
    document.querySelector('[data-booking-map-zoom-out]')?.addEventListener('click', () => { zoom = Math.max(.5, zoom - .25); applyZoom(); });
    document.querySelector('[data-booking-map-zoom-reset]')?.addEventListener('click', () => { zoom = 1; applyZoom(); });

    const content = dialog.querySelector('[data-booking-map-dialog-content]');
    const openGroup = (id) => {
        const template = document.querySelector(`[data-booking-map-template="${id}"]`);
        if (!template || !content) return;
        content.replaceChildren(template.content.cloneNode(true));
        dialog.showModal();
    };
    document.querySelectorAll('[data-booking-map-group]').forEach(button => {
        button.addEventListener('click', () => openGroup(button.dataset.bookingMapGroup));
    });
})();
</script>
</body>
</html>
