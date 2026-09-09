<?php
/** @var \FachDock\Auth\AuthenticatedStaff|null $staff */
/** @var \FachDock\Parent\AuthenticatedParent|null $parent */
/** @var bool $editable */
/** @var list<array<string,mixed>> $floors */
/** @var list<array<string,mixed>> $floorPlans */
/** @var int|null $selectedFloorId */
/** @var int|null $selectedPlanId */
/** @var list<array<string,mixed>> $schoolYears */
/** @var int|null $selectedSchoolYearId */
/** @var array<string,mixed>|null $plan */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$isAdminView = $staff !== null;
$basePath = $isAdminView ? '/admin/floorplans' : '/parent/floorplans';
$placedGroups = $plan !== null ? array_values(array_filter($plan['groups'], static fn (array $group): bool => (bool) $group['placed'])) : [];
$unplacedGroups = $plan !== null ? array_values(array_filter($plan['groups'], static fn (array $group): bool => !(bool) $group['placed'])) : [];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lagepläne · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell floorplan-page">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Schließfächer</p>
            <h1>Lagepläne</h1>
            <p><?= $isAdminView ? 'Schrankgruppen auf Etagenplänen positionieren und ihre Verfügbarkeit prüfen.' : 'Standorte und Verfügbarkeit der Schließfächer auf dem Etagenplan ansehen.' ?></p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= $e($error) ?></div>
    <?php endforeach; ?>

    <section class="card floorplan-filters">
        <form method="get" action="<?= $e($basePath) ?>" class="form-grid floorplan-filter-form">
            <label>Etage
                <select name="floor_id" onchange="this.form.submit()">
                    <?php foreach ($floors as $floor): ?>
                        <option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $selectedFloorId ? 'selected' : '' ?>>
                            <?= $e($floor['building_name']) ?> · <?= $e($floor['name']) ?> (<?= (int) $floor['plan_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($floorPlans !== []): ?>
                <label>Plan
                    <select name="plan_id" onchange="this.form.submit()">
                        <?php foreach ($floorPlans as $item): ?>
                            <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $selectedPlanId ? 'selected' : '' ?>><?= $e($item['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($schoolYears !== []): ?>
                <label>Verfügbarkeit für
                    <select name="school_year_id" onchange="this.form.submit()">
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $selectedSchoolYearId ? 'selected' : '' ?>><?= $e($year['label']) ?> · <?= $e($year['status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($editable): ?>
        <details class="card floorplan-upload" <?= $floorPlans === [] ? 'open' : '' ?>>
            <summary><strong>Neuen Lageplan hochladen</strong></summary>
            <form method="post" action="/admin/floorplans/upload" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label>Etage
                    <select name="floor_id" required>
                        <?php foreach ($floors as $floor): ?>
                            <option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $selectedFloorId ? 'selected' : '' ?>><?= $e($floor['building_name']) ?> · <?= $e($floor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Titel
                    <input type="text" name="title" maxlength="255" placeholder="z. B. Hauptflur" required>
                </label>
                <label>Bilddatei
                    <input type="file" name="floor_plan" accept="image/png,image/jpeg,image/webp" required>
                    <small>PNG, JPEG oder WebP, maximal 10 MB. SVG wird aus Sicherheitsgründen nicht akzeptiert.</small>
                </label>
                <div><button class="button" type="submit">Lageplan hochladen</button></div>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($plan === null): ?>
        <section class="card empty-state"><h2>Noch kein Lageplan</h2><p>Für die ausgewählte Etage ist noch kein Plan hinterlegt.</p></section>
    <?php else: ?>
        <section class="floorplan-layout">
            <div class="card floorplan-stage-card">
                <div class="floorplan-toolbar">
                    <div>
                        <strong><?= $e($plan['building_name']) ?> · <?= $e($plan['floor_name']) ?></strong>
                        <span><?= $e($plan['title']) ?></span>
                    </div>
                    <div class="button-row" aria-label="Zoom">
                        <button class="button button-secondary" type="button" data-floorplan-zoom-out>−</button>
                        <output data-floorplan-zoom-label>100 %</output>
                        <button class="button button-secondary" type="button" data-floorplan-zoom-in>+</button>
                        <button class="button button-secondary" type="button" data-floorplan-zoom-reset>100 %</button>
                    </div>
                </div>
                <div class="floorplan-legend" aria-label="Legende">
                    <span><i class="floorplan-dot floorplan-dot-free"></i> freie Fächer</span>
                    <span><i class="floorplan-dot floorplan-dot-full"></i> belegt/reserviert</span>
                    <span><i class="floorplan-dot floorplan-dot-warning"></i> technisch eingeschränkt</span>
                </div>
                <div class="floorplan-scroll" data-floorplan-scroll>
                    <div class="floorplan-canvas" data-floorplan-canvas data-editable="<?= $editable ? '1' : '0' ?>">
                        <img src="<?= $e($plan['image_url']) ?>" alt="<?= $e($plan['title']) ?>">
                        <?php foreach ($placedGroups as $group):
                            $json = json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $style = sprintf(
                                'left:%.3f%%;top:%.3f%%;width:%.3f%%;height:%.3f%%',
                                (float) $group['x_percent'],
                                (float) $group['y_percent'],
                                (float) $group['width_percent'],
                                (float) $group['height_percent'],
                            );
                        ?>
                            <button type="button"
                                class="floorplan-marker floorplan-marker-<?= $e($group['marker_status']) ?>"
                                style="<?= $e($style) ?>"
                                data-floorplan-marker
                                data-group-id="<?= (int) $group['id'] ?>"
                                data-group="<?= $e($json ?: '{}') ?>"
                                title="<?= $e($group['code']) ?>: <?= (int) $group['free_count'] ?> frei">
                                <span><?= $e($group['code']) ?></span>
                                <small><?= (int) $group['free_count'] ?>/<?= (int) $group['total_count'] ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <aside class="floorplan-sidebar">
                <section class="card">
                    <h2>Schrankgruppen</h2>
                    <div class="floorplan-group-summary">
                        <?php foreach ($plan['groups'] as $group): ?>
                            <button type="button" class="floorplan-group-row" data-floorplan-open-group="<?= (int) $group['id'] ?>">
                                <span class="floorplan-dot floorplan-dot-<?= $e($group['marker_status']) ?>"></span>
                                <span><strong><?= $e($group['code']) ?></strong><small><?= $e($group['area_name']) ?></small></span>
                                <span><?= (int) $group['free_count'] ?> frei</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($editable): ?>
                    <section class="card">
                        <h2>Positionierung</h2>
                        <p class="muted">Marker können auf dem Plan gezogen werden. Anschließend die geänderte Position speichern.</p>
                        <?php if ($unplacedGroups !== []): ?>
                            <form method="post" action="/admin/floorplans/placement" class="compact-form floorplan-add-placement">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                                <input type="hidden" name="x_percent" value="5">
                                <input type="hidden" name="y_percent" value="5">
                                <input type="hidden" name="width_percent" value="10">
                                <input type="hidden" name="height_percent" value="8">
                                <label>Schrankgruppe hinzufügen
                                    <select name="cabinet_group_id">
                                        <?php foreach ($unplacedGroups as $group): ?>
                                            <option value="<?= (int) $group['id'] ?>"><?= $e($group['code']) ?> · <?= $e($group['area_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="button button-secondary" type="submit">Auf Plan setzen</button>
                            </form>
                        <?php endif; ?>

                        <div class="floorplan-placement-list">
                            <?php foreach ($placedGroups as $group): ?>
                                <form method="post" action="/admin/floorplans/placement" class="floorplan-placement-form" data-placement-form="<?= (int) $group['id'] ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                    <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                                    <input type="hidden" name="cabinet_group_id" value="<?= (int) $group['id'] ?>">
                                    <div><strong><?= $e($group['code']) ?></strong><small><?= $e($group['area_name']) ?></small></div>
                                    <label>X %<input data-placement-x type="number" name="x_percent" step="0.001" min="0" max="98" value="<?= $e($group['x_percent']) ?>"></label>
                                    <label>Y %<input data-placement-y type="number" name="y_percent" step="0.001" min="0" max="98" value="<?= $e($group['y_percent']) ?>"></label>
                                    <label>B %<input type="number" name="width_percent" step="0.001" min="2" max="100" value="<?= $e($group['width_percent']) ?>"></label>
                                    <label>H %<input type="number" name="height_percent" step="0.001" min="2" max="100" value="<?= $e($group['height_percent']) ?>"></label>
                                    <button class="button button-secondary" type="submit">Speichern</button>
                                </form>
                                <form method="post" action="/admin/floorplans/placement/remove" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                                    <input type="hidden" name="school_year_id" value="<?= (int) $selectedSchoolYearId ?>">
                                    <input type="hidden" name="cabinet_group_id" value="<?= (int) $group['id'] ?>">
                                    <button class="link-button" type="submit">Vom Plan entfernen</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="card danger-zone">
                        <h2>Lageplan löschen</h2>
                        <form method="post" action="/admin/floorplans/delete" onsubmit="return confirm('Lageplan wirklich löschen?');">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
                            <input type="hidden" name="floor_id" value="<?= (int) $plan['floor_id'] ?>">
                            <button class="button button-danger" type="submit">Lageplan löschen</button>
                        </form>
                    </section>
                <?php endif; ?>
            </aside>
        </section>
    <?php endif; ?>
</main>

<dialog class="floorplan-dialog" data-floorplan-dialog>
    <form method="dialog"><button class="dialog-close" aria-label="Schließen">×</button></form>
    <div data-floorplan-dialog-content></div>
</dialog>

<script>
(() => {
    const canvas = document.querySelector('[data-floorplan-canvas]');
    const dialog = document.querySelector('[data-floorplan-dialog]');
    if (!canvas || !dialog) return;
    const content = dialog.querySelector('[data-floorplan-dialog-content]');
    let zoom = 1;
    const label = document.querySelector('[data-floorplan-zoom-label]');
    const applyZoom = () => {
        canvas.style.transform = `scale(${zoom})`;
        canvas.parentElement.style.width = `${zoom * 100}%`;
        canvas.parentElement.style.minHeight = `${canvas.offsetHeight * zoom}px`;
        if (label) label.textContent = `${Math.round(zoom * 100)} %`;
    };
    document.querySelector('[data-floorplan-zoom-in]')?.addEventListener('click', () => { zoom = Math.min(3, zoom + .25); applyZoom(); });
    document.querySelector('[data-floorplan-zoom-out]')?.addEventListener('click', () => { zoom = Math.max(.5, zoom - .25); applyZoom(); });
    document.querySelector('[data-floorplan-zoom-reset]')?.addEventListener('click', () => { zoom = 1; applyZoom(); });

    const groups = new Map();
    const openGroup = (group) => {
        const rows = group.lockers.map(locker => {
            const status = locker.availability === 'free' ? 'frei' : (locker.availability === 'unavailable' ? `nicht buchbar · ${locker.operating_status}` : (locker.reserved ? 'reserviert' : 'belegt'));
            return `<tr><td>${escapeHtml(locker.short_name)}</td><td>Korpus ${locker.corpus_position}</td><td><span class="floorplan-locker-status floorplan-locker-status-${locker.availability}">${escapeHtml(status)}</span></td></tr>`;
        }).join('');
        content.innerHTML = `<p class="eyebrow">${escapeHtml(group.area_name)}</p><h2>${escapeHtml(group.code)} · ${escapeHtml(group.name)}</h2><p>${group.free_count} frei · ${group.occupied_count} belegt/reserviert · ${group.unavailable_count} technisch eingeschränkt</p><div class="table-scroll"><table><thead><tr><th>Fach</th><th>Korpus</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        dialog.showModal();
    };
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));

    document.querySelectorAll('[data-floorplan-marker]').forEach(marker => {
        let group;
        try { group = JSON.parse(marker.dataset.group || '{}'); } catch (_) { group = {}; }
        groups.set(String(marker.dataset.groupId), group);
        marker.addEventListener('click', event => {
            if (marker.dataset.dragged === '1') { marker.dataset.dragged = '0'; event.preventDefault(); return; }
            openGroup(group);
        });
        if (canvas.dataset.editable !== '1') return;
        marker.addEventListener('pointerdown', event => {
            event.preventDefault();
            marker.setPointerCapture(event.pointerId);
            marker.dataset.dragged = '0';
            const rect = canvas.getBoundingClientRect();
            const markerRect = marker.getBoundingClientRect();
            const offsetX = event.clientX - markerRect.left;
            const offsetY = event.clientY - markerRect.top;
            const move = moveEvent => {
                marker.dataset.dragged = '1';
                const widthPercent = parseFloat(marker.style.width) || 8;
                const heightPercent = parseFloat(marker.style.height) || 8;
                let x = ((moveEvent.clientX - rect.left - offsetX) / rect.width) * 100;
                let y = ((moveEvent.clientY - rect.top - offsetY) / rect.height) * 100;
                x = Math.max(0, Math.min(100 - widthPercent, x));
                y = Math.max(0, Math.min(100 - heightPercent, y));
                marker.style.left = `${x}%`;
                marker.style.top = `${y}%`;
                const form = document.querySelector(`[data-placement-form="${marker.dataset.groupId}"]`);
                if (form) {
                    form.querySelector('[data-placement-x]').value = x.toFixed(3);
                    form.querySelector('[data-placement-y]').value = y.toFixed(3);
                    form.classList.add('floorplan-placement-dirty');
                }
            };
            const up = upEvent => {
                marker.releasePointerCapture(upEvent.pointerId);
                marker.removeEventListener('pointermove', move);
                marker.removeEventListener('pointerup', up);
            };
            marker.addEventListener('pointermove', move);
            marker.addEventListener('pointerup', up);
        });
    });
    document.querySelectorAll('[data-floorplan-open-group]').forEach(button => button.addEventListener('click', () => {
        const group = groups.get(String(button.dataset.floorplanOpenGroup));
        if (group) openGroup(group);
    }));
})();
</script>
</body>
</html>
