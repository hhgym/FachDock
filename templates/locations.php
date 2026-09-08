<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff|null $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $buildings */
/** @var list<array<string, mixed>> $floors */
/** @var list<array<string, mixed>> $areas */
/** @var list<array<string, mixed>> $corpusTypes */
/** @var list<array<string, mixed>> $cabinetGroups */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$isActive = static fn (array $row): bool => (int) ($row['active'] ?? 0) === 1;
$areaAvailable = static fn (array $area): bool => (int) ($area['active'] ?? 0) === 1
    && (int) ($area['floor_active'] ?? 0) === 1
    && (int) ($area['building_active'] ?? 0) === 1;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Standorte · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Standorte</div>
    <div class="topbar-actions"><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Schließfachstruktur</span>
        <h1>Standorte und Schrankgruppen</h1>
        <p>Die physische Hierarchie wird von Gebäude bis Schließfach aufgebaut. Interne Nummern werden automatisch erzeugt.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>1. Gebäude</h2>
        <form method="post" action="/admin/locations/buildings" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Kürzel<input name="code" required maxlength="32" placeholder="HHG"></label>
            <label>Name<input name="name" required maxlength="255" placeholder="Hauptgebäude"></label>
            <button class="button" type="submit">Gebäude anlegen</button>
        </form>
        <div class="entity-list">
            <?php foreach ($buildings as $building): ?>
                <details class="entity-row">
                    <summary><code><?= $e((string) $building['code']) ?></code> <?= $e((string) $building['name']) ?> <span class="status-pill"><?= $isActive($building) ? 'aktiv' : 'inaktiv' ?></span></summary>
                    <form method="post" action="/admin/locations/buildings/update" class="grid compact-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int) $building['id'] ?>">
                        <label>Kürzel<input name="code" required maxlength="32" value="<?= $e((string) $building['code']) ?>"></label>
                        <label>Name<input name="name" required maxlength="255" value="<?= $e((string) $building['name']) ?>"></label>
                        <label class="check-label"><input type="checkbox" name="active" value="1" <?= $isActive($building) ? 'checked' : '' ?>> Aktiv</label>
                        <button class="button button-secondary" type="submit">Änderungen speichern</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card stack">
        <h2>2. Etagen</h2>
        <form method="post" action="/admin/locations/floors" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Gebäude
                <select name="building_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= (int) $building['id'] ?>" <?= $isActive($building) ? '' : 'disabled' ?>><?= $e((string) $building['code']) ?> · <?= $e((string) $building['name']) ?><?= $isActive($building) ? '' : ' · inaktiv' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Etagenkürzel<input name="code" required maxlength="32" placeholder="1OG"></label>
            <label>Name<input name="name" required maxlength="255" placeholder="1. Obergeschoss"></label>
            <label>Sortierung<input name="sort_order" value="0" inputmode="numeric"></label>
            <button class="button" type="submit">Etage anlegen</button>
        </form>
        <div class="entity-list">
            <?php foreach ($floors as $floor): ?>
                <details class="entity-row">
                    <summary><?= $e((string) $floor['building_name']) ?> · <code><?= $e((string) $floor['code']) ?></code> <?= $e((string) $floor['name']) ?> <span class="status-pill"><?= $isActive($floor) ? 'aktiv' : 'inaktiv' ?></span></summary>
                    <form method="post" action="/admin/locations/floors/update" class="grid compact-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int) $floor['id'] ?>">
                        <label>Gebäude<select name="building_id" required><?php foreach ($buildings as $building): ?><option value="<?= (int) $building['id'] ?>" <?= (int) $floor['building_id'] === (int) $building['id'] ? 'selected' : '' ?> <?= !$isActive($building) && (int) $floor['building_id'] !== (int) $building['id'] ? 'disabled' : '' ?>><?= $e((string) $building['code']) ?> · <?= $e((string) $building['name']) ?></option><?php endforeach; ?></select></label>
                        <label>Kürzel<input name="code" required maxlength="32" value="<?= $e((string) $floor['code']) ?>"></label>
                        <label>Name<input name="name" required maxlength="255" value="<?= $e((string) $floor['name']) ?>"></label>
                        <label>Sortierung<input name="sort_order" inputmode="numeric" value="<?= (int) $floor['sort_order'] ?>"></label>
                        <label class="check-label"><input type="checkbox" name="active" value="1" <?= $isActive($floor) ? 'checked' : '' ?>> Aktiv</label>
                        <button class="button button-secondary" type="submit">Änderungen speichern</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card stack">
        <h2>3. Bereiche</h2>
        <form method="post" action="/admin/locations/areas" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Etage
                <select name="floor_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($floors as $floor): ?>
                        <?php $floorAvailable = $isActive($floor) && (int) ($floor['building_active'] ?? 0) === 1; ?>
                        <option value="<?= (int) $floor['id'] ?>" <?= $floorAvailable ? '' : 'disabled' ?>><?= $e((string) $floor['building_name']) ?> · <?= $e((string) $floor['code']) ?> · <?= $e((string) $floor['name']) ?><?= $floorAvailable ? '' : ' · inaktiv' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Bereichskürzel<input name="code" required maxlength="32" placeholder="78"></label>
            <label class="wide">Name<input name="name" required maxlength="255" placeholder="Flur 78"></label>
            <button class="button" type="submit">Bereich anlegen</button>
        </form>
        <div class="entity-list">
            <?php foreach ($areas as $area): ?>
                <details class="entity-row">
                    <summary><?= $e((string) $area['building_name']) ?> · <?= $e((string) $area['floor_code']) ?> · <code><?= $e((string) $area['code']) ?></code> <?= $e((string) $area['name']) ?> <span class="status-pill"><?= $isActive($area) ? 'aktiv' : 'inaktiv' ?></span></summary>
                    <form method="post" action="/admin/locations/areas/update" class="grid compact-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int) $area['id'] ?>">
                        <label>Etage<select name="floor_id" required><?php foreach ($floors as $floor): ?><option value="<?= (int) $floor['id'] ?>" <?= (int) $area['floor_id'] === (int) $floor['id'] ? 'selected' : '' ?>><?= $e((string) $floor['building_name']) ?> · <?= $e((string) $floor['code']) ?></option><?php endforeach; ?></select></label>
                        <label>Kürzel<input name="code" required maxlength="32" value="<?= $e((string) $area['code']) ?>"></label>
                        <label>Name<input name="name" required maxlength="255" value="<?= $e((string) $area['name']) ?>"></label>
                        <label class="check-label"><input type="checkbox" name="active" value="1" <?= $isActive($area) ? 'checked' : '' ?>> Aktiv</label>
                        <button class="button button-secondary" type="submit">Änderungen speichern</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card stack">
        <h2>4. Korpustypen</h2>
        <form method="post" action="/admin/locations/corpus-types" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Kürzel<input name="code" required maxlength="64" placeholder="3ER"></label>
            <label>Name<input name="name" required maxlength="255" placeholder="3er-Korpus"></label>
            <label>Fachanzahl<input name="compartment_count" required inputmode="numeric" placeholder="3"></label>
            <label>Barrierearme Positionen<input name="barrier_positions" placeholder="2"><small>Komma- oder leerzeichengetrennt, z. B. 2</small></label>
            <button class="button" type="submit">Korpustyp anlegen</button>
        </form>
        <div class="entity-list">
            <?php foreach ($corpusTypes as $type): ?>
                <details class="entity-row">
                    <summary><code><?= $e((string) $type['code']) ?></code> <?= $e((string) $type['name']) ?> · <?= (int) $type['compartment_count'] ?> Fächer <span class="status-pill"><?= $isActive($type) ? 'aktiv' : 'inaktiv' ?></span></summary>
                    <form method="post" action="/admin/locations/corpus-types/update" class="grid compact-form">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
                        <label>Kürzel<input name="code" required maxlength="64" value="<?= $e((string) $type['code']) ?>"></label>
                        <label>Name<input name="name" required maxlength="255" value="<?= $e((string) $type['name']) ?>"></label>
                        <label>Fachanzahl<input name="compartment_count" required inputmode="numeric" value="<?= (int) $type['compartment_count'] ?>"></label>
                        <label>Barrierearme Positionen<input name="barrier_positions" value="<?= $e((string) ($type['barrier_positions'] ?? '')) ?>"></label>
                        <label class="check-label"><input type="checkbox" name="active" value="1" <?= $isActive($type) ? 'checked' : '' ?>> Aktiv</label>
                        <button class="button button-secondary" type="submit">Änderungen speichern</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card stack">
        <h2>5. Schrankgruppe anlegen</h2>
        <form method="post" action="/admin/locations/cabinet-groups" class="grid sequence-builder">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Bereich
                <select name="area_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($areas as $area): ?>
                        <option value="<?= (int) $area['id'] ?>" <?= $areaAvailable($area) ? '' : 'disabled' ?>><?= $e((string) $area['building_name']) ?> · <?= $e((string) $area['floor_code']) ?> · <?= $e((string) $area['code']) ?> · <?= $e((string) $area['name']) ?><?= $areaAvailable($area) ? '' : ' · inaktiv' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Name optional<input name="name" maxlength="255" placeholder="Nordwand"></label>
            <div class="wide sequence-editor">
                <label>Korpus hinzufügen
                    <select class="sequence-picker">
                        <?php foreach ($corpusTypes as $type): ?><?php if ($isActive($type)): ?><option value="<?= (int) $type['id'] ?>" data-label="<?= $e((string) $type['code']) ?> · <?= $e((string) $type['name']) ?>"><?= $e((string) $type['code']) ?> · <?= $e((string) $type['name']) ?></option><?php endif; ?><?php endforeach; ?>
                    </select>
                </label>
                <button class="button button-secondary sequence-add" type="button">Korpus hinzufügen</button>
                <ol class="sequence-list"><li class="muted sequence-empty">Noch kein Korpus ausgewählt.</li></ol>
                <input type="hidden" name="corpus_sequence" class="sequence-value" required>
                <small>Reihenfolge entspricht links → rechts. Ein Korpustyp kann mehrfach hinzugefügt werden.</small>
            </div>
            <button class="button" type="submit">Schrankgruppe speichern</button>
        </form>
    </section>

    <section class="card stack">
        <h2>Schrankgruppen</h2>
        <?php if ($cabinetGroups === []): ?><p>Noch keine Schrankgruppe vorhanden.</p><?php endif; ?>
        <?php foreach ($cabinetGroups as $group): ?>
            <details class="entity-row">
                <summary>
                    <strong><?= $e((string) $group['code']) ?></strong>
                    · <?= $e((string) $group['building_name']) ?> · <?= $e((string) $group['floor_code']) ?>-<?= $e((string) $group['area_code']) ?>
                    · <?= (int) $group['corpus_count'] ?> Korpusse / <?= (int) $group['locker_count'] ?> Fächer
                    <span class="status-pill"><?= $isActive($group) ? 'aktiv' : 'inaktiv' ?></span>
                </summary>
                <div class="stack compact-form">
                    <form method="post" action="/admin/locations/cabinet-groups/update" class="grid">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                        <label>Bereich<select name="area_id" required><?php foreach ($areas as $area): ?><option value="<?= (int) $area['id'] ?>" <?= (int) $group['area_id'] === (int) $area['id'] ? 'selected' : '' ?>><?= $e((string) $area['building_name']) ?> · <?= $e((string) $area['floor_code']) ?> · <?= $e((string) $area['code']) ?></option><?php endforeach; ?></select></label>
                        <label>Name<input name="name" maxlength="255" value="<?= $e((string) ($group['name'] ?? '')) ?>"></label>
                        <label class="check-label"><input type="checkbox" name="active" value="1" <?= $isActive($group) ? 'checked' : '' ?>> Aktiv</label>
                        <button class="button button-secondary" type="submit">Metadaten speichern</button>
                    </form>

                    <?php if ($group['structure_locked_at'] === null): ?>
                        <form method="post" action="/admin/locations/cabinet-groups/restructure" class="stack sequence-builder">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                            <div class="sequence-editor">
                                <strong>Struktur neu erzeugen</strong>
                                <label>Korpus hinzufügen<select class="sequence-picker"><?php foreach ($corpusTypes as $type): ?><?php if ($isActive($type)): ?><option value="<?= (int) $type['id'] ?>" data-label="<?= $e((string) $type['code']) ?> · <?= $e((string) $type['name']) ?>"><?= $e((string) $type['code']) ?> · <?= $e((string) $type['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                                <button class="button button-secondary sequence-add" type="button">Korpus hinzufügen</button>
                                <ol class="sequence-list"><li class="muted sequence-empty">Neue Reihenfolge zusammenstellen.</li></ol>
                                <input type="hidden" name="corpus_sequence" class="sequence-value" required>
                            </div>
                            <button class="button button-secondary" type="submit">Struktur ersetzen</button>
                        </form>
                        <form method="post" action="/admin/locations/cabinet-groups/delete" onsubmit="return confirm('Ungenutzte Schrankgruppe wirklich löschen?');">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                            <button class="link-button danger-link" type="submit">Ungenutzte Schrankgruppe löschen</button>
                        </form>
                        <small class="muted">Struktur und Löschung sind nur möglich, solange noch keine historische Nutzung vorliegt.</small>
                    <?php else: ?>
                        <div class="alert alert-neutral">Die Korpusreihenfolge ist dauerhaft gesperrt. Bezeichnungen und Standortzuordnung können weiterhin gepflegt werden.</div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
</main>
<script>
for (const builder of document.querySelectorAll('.sequence-builder')) {
    const picker = builder.querySelector('.sequence-picker');
    const add = builder.querySelector('.sequence-add');
    const list = builder.querySelector('.sequence-list');
    const output = builder.querySelector('.sequence-value');
    if (!(picker instanceof HTMLSelectElement) || !(add instanceof HTMLButtonElement)
        || !(list instanceof HTMLOListElement) || !(output instanceof HTMLInputElement)) {
        continue;
    }

    const values = [];
    const render = () => {
        list.replaceChildren();
        if (values.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'muted sequence-empty';
            empty.textContent = 'Noch kein Korpus ausgewählt.';
            list.append(empty);
            output.value = '';
            return;
        }
        values.forEach((entry, index) => {
            const item = document.createElement('li');
            item.textContent = `${index + 1}. ${entry.label} `;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'mini-button';
            remove.textContent = 'entfernen';
            remove.addEventListener('click', () => {
                values.splice(index, 1);
                render();
            });
            item.append(remove);
            list.append(item);
        });
        output.value = values.map((entry) => entry.id).join(',');
    };

    add.addEventListener('click', () => {
        const option = picker.selectedOptions[0];
        if (!option) return;
        values.push({id: option.value, label: option.dataset.label || option.textContent || option.value});
        render();
    });
}
</script>
</body>
</html>
