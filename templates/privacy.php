<?php
/** @var \FachDock\Auth\AuthenticatedStaff $staff */
/** @var array{retention_years:int,mail_retention_days:int} $settings */
/** @var array<string,mixed> $preview */
/** @var list<array<string,mixed>> $runs */
/** @var array<string,mixed> $readiness */
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
    <title>Datenschutz & Produktionscheck · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell platform-page">
    <div class="page-heading"><div><p class="eyebrow">System</p><h1>Datenschutz & Produktionscheck</h1><p>Aufbewahrung, Anonymisierung, Datenexporte und technische Produktionsbereitschaft.</p></div></div>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= $e($error) ?></div><?php endforeach; ?>
    <?php if ($notice !== null): ?><div class="alert alert-success"><?= $e($notice) ?></div><?php endif; ?>

    <section class="card platform-section">
        <div class="school-year-heading">
            <div><p class="eyebrow">Produktionsbereitschaft</p><h2><?= $readiness['ready'] ? 'Keine blockierenden Prüfpunkte' : 'Noch nicht produktionsbereit' ?></h2><p><?= (int) $readiness['failures'] ?> Fehler · <?= (int) $readiness['warnings'] ?> Warnung(en)</p></div>
            <span class="badge"><?= $readiness['ready'] ? 'bereit' : 'Handlungsbedarf' ?></span>
        </div>
        <div class="readiness-list">
            <?php foreach ($readiness['checks'] as $check): ?>
                <div class="readiness-item readiness-item-<?= $e($check['status']) ?>">
                    <span><?= $check['status'] === 'ok' ? '✓' : ($check['status'] === 'warning' ? '!' : '×') ?></span>
                    <div><strong><?= $e($check['label']) ?></strong><small><?= $e($check['detail']) ?></small></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="platform-grid">
        <article class="card platform-section">
            <h2>Aufbewahrungsfristen</h2>
            <p>Die Fristen steuern nur den explizit ausgelösten Datenschutzlauf. FachDock löscht personenbezogene Stammdaten nicht unbemerkt bei einem normalen Seitenaufruf.</p>
            <form method="post" action="/admin/privacy/settings" class="stack">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label>Schüler-/Eltern-Stammdaten nach Abschluss
                    <input type="number" name="retention_years" min="1" max="30" value="<?= (int) $settings['retention_years'] ?>" required>
                    <small>Jahre. Anonymisiert werden nur inaktive Schülerinnen und Schüler ohne Buchungen innerhalb dieser Frist.</small>
                </label>
                <label>E-Mail-Inhalte und Versanddaten
                    <input type="number" name="mail_retention_days" min="30" max="3650" value="<?= (int) $settings['mail_retention_days'] ?>" required>
                    <small>Tage. Danach werden Empfänger, Betreff, Nachrichtentext und Platzhalter bereinigt.</small>
                </label>
                <button class="button" type="submit">Fristen speichern</button>
            </form>
        </article>

        <article class="card platform-section danger-zone">
            <h2>Anonymisierungsvorschau</h2>
            <p>Stichtag Stammdaten: <strong><?= $e($preview['cutoff_date']) ?></strong><br>E-Mail-Stichtag: <strong><?= $e($preview['mail_cutoff_date']) ?></strong></p>
            <div class="privacy-preview">
                <div><span>Schüler</span><strong><?= (int) $preview['students'] ?></strong></div>
                <div><span>Eltern</span><strong><?= (int) $preview['parents'] ?></strong></div>
                <div><span>E-Mails</span><strong><?= (int) $preview['mails'] ?></strong></div>
            </div>
            <p class="muted">Bei alten Audit-Einträgen werden Metadaten entfernt; Aktion, Entität und Zeitstempel bleiben zur Nachvollziehbarkeit erhalten. Buchungs- und Zahlungsdatensätze bleiben pseudonymisiert bestehen.</p>
            <form method="post" action="/admin/privacy/anonymize" class="stack">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label>Bestätigung
                    <input type="text" name="confirmation" autocomplete="off" placeholder="ANONYMISIEREN" required>
                    <small>Dieser Vorgang ist nicht automatisch rückgängig zu machen.</small>
                </label>
                <button class="button button-danger" type="submit">Fällige Daten anonymisieren</button>
            </form>
        </article>
    </section>

    <section class="card platform-section">
        <h2>CSV-Exporte</h2>
        <p>Exporte enthalten die jeweils aktuell gespeicherten Daten und werden als administrative Aktion protokolliert.</p>
        <div class="export-grid">
            <?php foreach ([
                ['bookings','Buchungen','Schüler, Schuljahr, Fach und Gebühren'],
                ['payments','Zahlungen','Zahlungsstatus und Stripe-Referenzen'],
                ['incidents','Meldungen','Defekte, Notöffnungen und Bearbeitungsstatus'],
                ['audit','Audit-Protokoll','Administrative und Elternaktionen'],
            ] as [$key,$label,$description]): ?>
                <div class="export-card"><strong><?= $e($label) ?></strong><small><?= $e($description) ?></small><a class="button button-secondary" href="/admin/privacy/export?type=<?= $e($key) ?>">CSV herunterladen</a></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card platform-section">
        <h2>Letzte Datenschutzläufe</h2>
        <?php if ($runs === []): ?><p class="muted">Noch kein Anonymisierungslauf durchgeführt.</p><?php else: ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Zeit</th><th>Stichtag</th><th>Status</th><th>Administrator</th><th>Ergebnis</th></tr></thead><tbody>
                <?php foreach ($runs as $run): ?><tr><td><?= $e($run['started_at']) ?></td><td><?= $e($run['cutoff_date']) ?></td><td><?= $e($run['status']) ?></td><td><?= $e($run['staff_name']) ?></td><td><code><?= $e($run['summary_json']) ?></code></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
