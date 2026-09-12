<?php
/** @var \FachDock\Auth\AuthenticatedStaff $staff */
/** @var array{retention_years:int,mail_retention_days:int} $settings */
/** @var array{student_deactivation_days:int,student_anonymization_days:int,parent_deactivation_days:int,parent_anonymization_days:int} $accountSettings */
/** @var array<string,int> $accountPreview */
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
    <div class="page-heading"><div><p class="eyebrow">System</p><h1>Datenschutz & Produktionscheck</h1><p>Account-Lifecycle, Aufbewahrung, Anonymisierung, Datenexporte und technische Produktionsbereitschaft.</p></div></div>
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

    <form method="post" action="/admin/privacy/settings" class="stack">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <section class="card platform-section">
            <p class="eyebrow">Benutzerkonten</p><h2>Schüler- und Eltern-Lifecycle</h2>
            <p>Der Fristbeginn bei Schülerinnen und Schülern ist der erste Wechsel der Stammdaten auf <code>active = false</code>. Bei Eltern beginnt die Frist, sobald kein aktives Kind mehr verknüpft ist. Automatische Reaktivierungen erfolgen nur bei zuvor automatisch deaktivierten Konten.</p>
            <div class="form-grid">
                <label>Schüler: Deaktivierung nach
                    <input type="number" name="student_deactivation_days" min="1" max="3650" value="<?= (int) $accountSettings['student_deactivation_days'] ?>" required>
                    <small>Tage nach dem Inaktivwerden. Standard: 30 Tage.</small>
                </label>
                <label>Schüler: Anonymisierung nach
                    <input type="number" name="student_anonymization_days" min="1" max="3650" value="<?= (int) $accountSettings['student_anonymization_days'] ?>" required>
                    <small>Tage nach dem Inaktivwerden. Standard: 365 Tage; darf nicht vor der Deaktivierung liegen.</small>
                </label>
                <label>Eltern: Deaktivierung nach
                    <input type="number" name="parent_deactivation_days" min="1" max="7300" value="<?= (int) $accountSettings['parent_deactivation_days'] ?>" required>
                    <small>Tage ohne verknüpftes aktives Kind. Standard: 1095 Tage (3 Jahre).</small>
                </label>
                <label>Eltern: Anonymisierung nach
                    <input type="number" name="parent_anonymization_days" min="1" max="7300" value="<?= (int) $accountSettings['parent_anonymization_days'] ?>" required>
                    <small>Standard ebenfalls 1095 Tage; kann bei Bedarf später als die Deaktivierung gesetzt werden.</small>
                </label>
            </div>
            <div class="privacy-preview">
                <div><span>Schüler: Sperre fällig</span><strong><?= (int) $accountPreview['students_to_deactivate'] ?></strong></div>
                <div><span>Schüler: Anonymisierung fällig</span><strong><?= (int) $accountPreview['students_to_anonymize'] ?></strong></div>
                <div><span>Eltern: Sperre fällig</span><strong><?= (int) $accountPreview['parents_to_deactivate'] ?></strong></div>
                <div><span>Eltern: Anonymisierung fällig</span><strong><?= (int) $accountPreview['parents_to_anonymize'] ?></strong></div>
            </div>
            <p class="muted">Der tägliche Befehl <code>privacy:tick</code> führt diese Lifecycle-Aktionen automatisch aus.</p>
        </section>

        <section class="platform-grid">
            <article class="card platform-section">
                <h2>Weitere Aufbewahrungsfristen</h2>
                <label>Historische Audit-/Geschäftsmetadaten
                    <input type="number" name="retention_years" min="1" max="30" value="<?= (int) $settings['retention_years'] ?>" required>
                    <small>Jahre. Diese allgemeine Frist steuert nicht mehr den Schüler-/Eltern-Account-Lifecycle.</small>
                </label>
                <label>E-Mail-Inhalte und Versanddaten
                    <input type="number" name="mail_retention_days" min="30" max="3650" value="<?= (int) $settings['mail_retention_days'] ?>" required>
                    <small>Tage. Danach werden Empfänger, Betreff, Nachrichtentext und Platzhalter bereinigt.</small>
                </label>
                <button class="button" type="submit">Alle Fristen speichern</button>
            </article>

            <article class="card platform-section danger-zone">
                <h2>Anonymisierungsvorschau</h2>
                <p>Historienstichtag: <strong><?= $e($preview['cutoff_date']) ?></strong><br>E-Mail-Stichtag: <strong><?= $e($preview['mail_cutoff_date']) ?></strong></p>
                <div class="privacy-preview">
                    <div><span>Schüler</span><strong><?= (int) $preview['students'] ?></strong></div>
                    <div><span>Eltern</span><strong><?= (int) $preview['parents'] ?></strong></div>
                    <div><span>E-Mails</span><strong><?= (int) $preview['mails'] ?></strong></div>
                </div>
                <p class="muted">Bei alten Audit-Einträgen werden Metadaten entfernt; Aktion, Entität und Zeitstempel bleiben zur Nachvollziehbarkeit erhalten. Buchungs- und Zahlungsdatensätze bleiben pseudonymisiert bestehen.</p>
            </article>
        </section>
    </form>

    <section class="card platform-section danger-zone">
        <h2>Fällige Anonymisierung jetzt ausführen</h2>
        <p>Führt sowohl fällige Account-Lifecycle-Aktionen als auch die übrige Datenschutzbereinigung aus.</p>
        <form method="post" action="/admin/privacy/anonymize" class="stack">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Bestätigung
                <input type="text" name="confirmation" autocomplete="off" placeholder="ANONYMISIEREN" required>
                <small>Zur Bestätigung exakt <code>ANONYMISIEREN</code> eingeben.</small>
            </label>
            <button class="button button-danger" type="submit">Fällige Daten anonymisieren</button>
        </form>
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
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Zeit</th><th>Stichtag</th><th>Status</th><th>Auslöser</th><th>Ergebnis</th></tr></thead><tbody>
                <?php foreach ($runs as $run): ?><tr><td><?= $e($run['started_at']) ?></td><td><?= $e($run['cutoff_date']) ?></td><td><?= $e($run['status']) ?></td><td><?= $run['staff_name'] === null ? 'Automatischer Job' : $e($run['staff_name']) ?></td><td><code><?= $e($run['summary_json']) ?></code></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
