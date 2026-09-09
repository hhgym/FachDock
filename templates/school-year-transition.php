<?php
/** @var \FachDock\Auth\AuthenticatedStaff $staff */
/** @var list<array<string,mixed>> $pairs */
/** @var int|null $selectedSourceId */
/** @var int|null $selectedTargetId */
/** @var array<string,mixed>|null $preview */
/** @var list<array<string,mixed>> $runs */
/** @var string|null $notice */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schuljahreswechsel · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell platform-page">
    <div class="page-heading">
        <div><p class="eyebrow">Konfiguration</p><h1>Schuljahreswechsel</h1><p>Erinnerungen, Vorschau und automatisierte Freigabe zum 1. August.</p></div>
    </div>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= $e($error) ?></div><?php endforeach; ?>
    <?php if ($notice !== null): ?><div class="alert alert-success"><?= $e($notice) ?></div><?php endif; ?>

    <section class="card platform-section">
        <h2>Übergang auswählen</h2>
        <?php if ($pairs === []): ?>
            <p>Es sind noch keine unmittelbar aufeinanderfolgenden Schuljahre vorhanden.</p>
        <?php else: ?>
            <form method="get" action="/admin/school-year-transition" class="form-grid transition-pair-form">
                <label>Übergang
                    <select data-transition-pair>
                        <?php foreach ($pairs as $pair):
                            $selected = (int) $pair['source_id'] === $selectedSourceId && (int) $pair['target_id'] === $selectedTargetId;
                        ?>
                            <option value="<?= (int) $pair['source_id'] ?>:<?= (int) $pair['target_id'] ?>" <?= $selected ? 'selected' : '' ?>><?= $e($pair['source_label']) ?> → <?= $e($pair['target_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <input type="hidden" name="source_school_year_id" value="<?= (int) $selectedSourceId ?>" data-transition-source>
                <input type="hidden" name="target_school_year_id" value="<?= (int) $selectedTargetId ?>" data-transition-target>
                <div><button class="button button-secondary" type="submit">Vorschau laden</button></div>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($preview !== null): ?>
        <section class="platform-metrics">
            <article class="card metric-card"><span>Aktuelle Buchungen</span><strong><?= (int) $preview['active_source'] ?></strong></article>
            <article class="card metric-card"><span>Bereits verlängert</span><strong><?= (int) $preview['already_target'] ?></strong></article>
            <article class="card metric-card"><span>Altes Fach möglich</span><strong><?= (int) $preview['same_locker_possible'] ?></strong></article>
            <article class="card metric-card metric-warning"><span>Fachwechsel nötig</span><strong><?= (int) $preview['requires_change'] ?></strong></article>
            <article class="card metric-card"><span>Schulabgang</span><strong><?= (int) $preview['leaving_school'] ?></strong></article>
            <article class="card metric-card metric-warning"><span>Noch ohne Folgebuchung</span><strong><?= (int) $preview['unrenewed'] ?></strong></article>
        </section>

        <section class="platform-grid">
            <article class="card platform-section">
                <h2>Erinnerungen</h2>
                <p>FachDock verschickt die drei Stufen bei einem täglichen Cronlauf automatisch. Die Schaltflächen erlauben einen manuellen Lauf; bereits versandte Erinnerungen werden dedupliziert.</p>
                <div class="stack">
                    <?php foreach ([['june_01','1. Juni'],['july_01','1. Juli'],['july_20','20. Juli']] as [$key,$label]): ?>
                        <form method="post" action="/admin/school-year-transition/remind" class="action-row">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="source_school_year_id" value="<?= (int) $selectedSourceId ?>">
                            <input type="hidden" name="target_school_year_id" value="<?= (int) $selectedTargetId ?>">
                            <input type="hidden" name="reminder_key" value="<?= $e($key) ?>">
                            <span><strong><?= $e($label) ?></strong><small>nur Eltern ohne Folgebuchung</small></span>
                            <button class="button button-secondary" type="submit">Jetzt prüfen & einreihen</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card platform-section danger-zone">
                <h2>Schuljahreswechsel durchführen</h2>
                <p>Ab dem <strong><?= $e($preview['target']['starts_on']) ?></strong> werden alle noch laufenden Buchungen aus <?= $e($preview['source']['label']) ?> geschlossen und ihre Fächer freigegeben. Vorhandene Folgebuchungen im Zieljahr bleiben unverändert.</p>
                <p>Der Vorgang ist idempotent und wird dauerhaft protokolliert.</p>
                <form method="post" action="/admin/school-year-transition/apply" class="stack">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="source_school_year_id" value="<?= (int) $selectedSourceId ?>">
                    <input type="hidden" name="target_school_year_id" value="<?= (int) $selectedTargetId ?>">
                    <label>Bestätigung
                        <input type="text" name="confirmation" autocomplete="off" placeholder="SCHULJAHRESWECHSEL" required>
                        <small>Zur Sicherheit exakt SCHULJAHRESWECHSEL eingeben.</small>
                    </label>
                    <button class="button button-danger" type="submit">Schuljahreswechsel ausführen</button>
                </form>
            </article>
        </section>
    <?php endif; ?>

    <section class="card platform-section">
        <h2>Automatik per Cron</h2>
        <p>Der tägliche Systemjob holt fällige Erinnerungsstufen nach und führt einen überfälligen Wechsel ab dem 1. August genau einmal aus:</p>
        <code class="code-block">php bin/fachdock school-year:tick</code>
        <p class="muted">Empfohlen: einmal täglich am frühen Morgen. Ein ausgefallener Lauf am Stichtag ist unkritisch; der nächste Lauf holt ihn nach.</p>
    </section>

    <section class="card platform-section">
        <h2>Letzte Durchläufe</h2>
        <?php if ($runs === []): ?><p class="muted">Noch kein Schuljahreswechsel protokolliert.</p><?php else: ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Zeit</th><th>Übergang</th><th>Status</th><th>Akteur</th><th>Ergebnis</th></tr></thead><tbody>
            <?php foreach ($runs as $run): ?>
                <tr><td><?= $e($run['started_at']) ?></td><td><?= $e($run['source_label']) ?> → <?= $e($run['target_label']) ?></td><td><?= $e($run['status']) ?></td><td><?= $e($run['staff_name'] ?? $run['initiated_by_type']) ?></td><td><code><?= $e($run['summary_json']) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</main>
<script>
(() => {
    const select = document.querySelector('[data-transition-pair]');
    if (!select) return;
    const sync = () => {
        const [source, target] = select.value.split(':');
        document.querySelector('[data-transition-source]').value = source || '';
        document.querySelector('[data-transition-target]').value = target || '';
    };
    select.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
