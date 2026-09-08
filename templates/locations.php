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
        <p>Gebäude, Etagen, Bereiche, Korpustypen und komplette Schrankgruppen verwalten.</p>
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
        <p class="form-hint">Vorhanden: <?php foreach ($buildings as $building): ?><code><?= $e((string) $building['code']) ?></code> <?= $e((string) $building['name']) ?> · <?php endforeach; ?></p>
    </section>

    <section class="card stack">
        <h2>2. Etagen</h2>
        <form method="post" action="/admin/locations/floors" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Gebäude-ID<input name="building_id" required inputmode="numeric"></label>
            <label>Etagenkürzel<input name="code" required maxlength="32" placeholder="1OG"></label>
            <label>Name<input name="name" required maxlength="255" placeholder="1. Obergeschoss"></label>
            <label>Sortierung<input name="sort_order" value="0" inputmode="numeric"></label>
            <button class="button" type="submit">Etage anlegen</button>
        </form>
        <p class="form-hint"><?php foreach ($floors as $floor): ?>ID <?= (int) $floor['id'] ?> · <?= $e((string) $floor['building_name']) ?> · <code><?= $e((string) $floor['code']) ?></code> <?= $e((string) $floor['name']) ?><br><?php endforeach; ?></p>
    </section>

    <section class="card stack">
        <h2>3. Bereiche</h2>
        <form method="post" action="/admin/locations/areas" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Etagen-ID<input name="floor_id" required inputmode="numeric"></label>
            <label>Bereichskürzel<input name="code" required maxlength="32" placeholder="78"></label>
            <label class="wide">Name<input name="name" required maxlength="255" placeholder="Flur 78"></label>
            <button class="button" type="submit">Bereich anlegen</button>
        </form>
        <p class="form-hint"><?php foreach ($areas as $area): ?>ID <?= (int) $area['id'] ?> · <?= $e((string) $area['building_name']) ?> · <?= $e((string) $area['floor_code']) ?> · <code><?= $e((string) $area['code']) ?></code> <?= $e((string) $area['name']) ?><br><?php endforeach; ?></p>
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
        <p class="form-hint"><?php foreach ($corpusTypes as $type): ?>ID <?= (int) $type['id'] ?> · <code><?= $e((string) $type['code']) ?></code> <?= $e((string) $type['name']) ?> · <?= (int) $type['compartment_count'] ?> Fächer · barrierearm: <?= $e((string) ($type['barrier_positions'] ?? '')) ?><br><?php endforeach; ?></p>
    </section>

    <section class="card stack">
        <h2>5. Schrankgruppe anlegen</h2>
        <form method="post" action="/admin/locations/cabinet-groups" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Bereich-ID<input name="area_id" required inputmode="numeric"></label>
            <label>Name optional<input name="name" maxlength="255" placeholder="Nordwand"></label>
            <label class="wide">Korpustyp-IDs in Reihenfolge<input name="corpus_sequence" required placeholder="1,1,2,1"><small>Links nach rechts; Wiederholungen sind erlaubt. Die Nummerierung der Korpusse und Fächer wird automatisch erzeugt.</small></label>
            <button class="button" type="submit">Schrankgruppe speichern</button>
        </form>
    </section>

    <section class="card stack">
        <h2>Schrankgruppen</h2>
        <?php if ($cabinetGroups === []): ?><p>Noch keine Schrankgruppe vorhanden.</p><?php endif; ?>
        <?php foreach ($cabinetGroups as $group): ?>
            <div class="session-row">
                <div>
                    <strong><?= $e((string) $group['code']) ?></strong>
                    · <?= $e((string) $group['floor_code']) ?>-<?= $e((string) $group['area_code']) ?>
                    · <?= (int) $group['corpus_count'] ?> Korpusse / <?= (int) $group['locker_count'] ?> Fächer
                    <?php if ($group['structure_locked_at'] !== null): ?><br><small class="muted">Struktur dauerhaft gesperrt</small><?php else: ?><br><small class="muted">Struktur noch änderbar, solange keine historische Nutzung vorliegt</small><?php endif; ?>
                </div>
                <?php if ($group['structure_locked_at'] === null): ?>
                    <form method="post" action="/admin/locations/cabinet-groups/restructure" class="stack">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                        <label>Neue Korpustyp-Reihenfolge<input name="corpus_sequence" required placeholder="1,2,1"></label>
                        <button class="button button-secondary" type="submit">Struktur neu erzeugen</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
