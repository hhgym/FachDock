<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Student\StudentImportPreview;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var StudentImportPreview|null $preview */
/** @var array{token:string,path:string,filename:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>,full_import:bool,profile_id:?int}|null $pending */
/** @var list<array{id:int,name:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>}> $profiles */
/** @var list<string> $errors */
/** @var bool $success */
/** @var array{total:int,active:int,inactive:int} $stats */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$labels = [
    'new' => 'Neu',
    'changed' => 'Geändert',
    'unchanged' => 'Unverändert',
    'reactivated' => 'Reaktiviert',
    'invalid' => 'Ungültig',
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schüler · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Schüler</div>
    <div class="topbar-actions"><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Schülerstammdaten</span>
        <h1>CSV-Import</h1>
        <p>Schülerdaten zunächst prüfen und erst nach einer Vorschau übernehmen.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert">Der Schülerimport wurde erfolgreich abgeschlossen.</div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Bestand</h2>
        <p><strong><?= $stats['total'] ?></strong> Schüler insgesamt · <strong><?= $stats['active'] ?></strong> aktiv · <strong><?= $stats['inactive'] ?></strong> inaktiv</p>
    </section>

    <section class="card stack">
        <h2>Importprofile</h2>
        <?php if ($profiles === []): ?>
            <p class="form-hint">Noch kein Profil vorhanden. Ohne Profil versucht FachDock übliche Spaltennamen automatisch zu erkennen.</p>
        <?php else: ?>
            <p class="form-hint">Vorhanden: <?php foreach ($profiles as $profile): ?><strong><?= $e($profile['name']) ?></strong> (<?= $e($profile['delimiter'] === "\t" ? 'Tab' : $profile['delimiter']) ?>, <?= $e($profile['encoding']) ?>) · <?php endforeach; ?></p>
        <?php endif; ?>

        <details>
            <summary>Neues Importprofil anlegen</summary>
            <form method="post" action="/admin/students/import/profiles" class="grid" style="margin-top:1rem">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label>Profilname<input name="profile_name" required maxlength="255" placeholder="Untis Schülerexport"></label>
                <label>Trennzeichen
                    <select name="profile_delimiter">
                        <option value=";">Semikolon ;</option>
                        <option value=",">Komma ,</option>
                        <option value="tab">Tabulator</option>
                    </select>
                </label>
                <label>Zeichenkodierung
                    <select name="profile_encoding">
                        <option value="UTF-8">UTF-8</option>
                        <option value="WINDOWS-1252">Windows-1252</option>
                        <option value="ISO-8859-1">ISO-8859-1</option>
                    </select>
                </label>
                <label>Spalte Matrikelnummer<input name="header_matrikelnummer" required placeholder="Matrikelnummer"></label>
                <label>Spalte Vorname<input name="header_first_name" required placeholder="Vorname"></label>
                <label>Spalte Nachname<input name="header_last_name" required placeholder="Nachname"></label>
                <label>Spalte Klasse<input name="header_class_name" required placeholder="Klasse"></label>
                <label>Spalte Aktiv<input name="header_active" required placeholder="Aktiv"></label>
                <label>Spalte Stufe optional<input name="header_grade" placeholder="Stufe"></label>
                <label>Spalte E-Mail optional<input name="header_email" placeholder="Email"></label>
                <button class="button" type="submit">Importprofil speichern</button>
            </form>
        </details>
    </section>

    <section class="card stack">
        <h2>CSV-Datei auswählen</h2>
        <p class="form-hint">Pflichtdaten: Matrikelnummer, Vorname, Nachname, Klasse, Aktiv. Optional: Stufe und E-Mail. Die Stufe wird sonst aus der Klassenbezeichnung abgeleitet.</p>
        <form method="post" action="/admin/students/import/preview" enctype="multipart/form-data" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label class="wide">CSV-Datei<input type="file" name="csv_file" accept=".csv,text/csv" required></label>
            <label>Importprofil
                <select name="profile_id">
                    <option value="">Automatische Spaltenerkennung</option>
                    <?php foreach ($profiles as $profile): ?>
                        <option value="<?= $profile['id'] ?>"><?= $e($profile['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Trennzeichen ohne Profil
                <select name="delimiter">
                    <option value=";">Semikolon ;</option>
                    <option value=",">Komma ,</option>
                    <option value="tab">Tabulator</option>
                </select>
            </label>
            <label>Zeichenkodierung ohne Profil
                <select name="encoding">
                    <option value="UTF-8">UTF-8</option>
                    <option value="WINDOWS-1252">Windows-1252</option>
                    <option value="ISO-8859-1">ISO-8859-1</option>
                </select>
            </label>
            <label class="wide"><input type="checkbox" name="full_import" value="1"> Vollständiger Import: aktive Schüler, die in der Datei fehlen, nach Bestätigung deaktivieren.</label>
            <button class="button" type="submit">Import prüfen</button>
        </form>
    </section>

    <?php if ($preview !== null && $pending !== null): ?>
        <?php $counts = $preview->counts(); ?>
        <section class="card stack">
            <h2>Importvorschau</h2>
            <p><strong><?= $e($pending['filename']) ?></strong><?php if ($pending['profile_id'] !== null): ?> · Profil-ID <?= $pending['profile_id'] ?><?php endif; ?><?php if ($pending['full_import']): ?> · vollständiger Import<?php else: ?> · Teilimport<?php endif; ?></p>
            <p>
                Neu: <?= $counts['new'] ?? 0 ?> · Geändert: <?= $counts['changed'] ?? 0 ?> · Reaktiviert: <?= $counts['reactivated'] ?? 0 ?> · Deaktiviert: <?= $counts['deactivated'] ?? 0 ?> · Unverändert: <?= $counts['unchanged'] ?? 0 ?> · Ungültig: <?= $counts['invalid'] ?? 0 ?>
            </p>

            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Zeile</th><th>Status</th><th>Matrikelnummer</th><th>Name</th><th>Klasse</th><th>Stufe</th><th>Aktiv</th><th>Hinweis</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview->rows as $row): ?>
                        <tr>
                            <td><?= $row['line'] ?></td>
                            <td><?= $e($labels[$row['category']] ?? $row['category']) ?></td>
                            <td><code><?= $e($row['matrikelnummer']) ?></code></td>
                            <td><?= $e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= $e($row['class_name']) ?></td>
                            <td><?= $row['grade'] ?></td>
                            <td><?= $row['active'] ? 'ja' : 'nein' ?></td>
                            <td><?= $e(implode(' ', $row['messages'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pending['full_import'] && $preview->deactivations !== []): ?>
                <div class="alert alert-error">
                    <strong>Diese bisher aktiven Schüler fehlen in der Datei und würden deaktiviert:</strong>
                </div>
                <div style="overflow-x:auto">
                    <table>
                        <thead><tr><th>Matrikelnummer</th><th>Name</th><th>Klasse</th><th>Stufe</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview->deactivations as $student): ?>
                            <tr>
                                <td><code><?= $e($student['matrikelnummer']) ?></code></td>
                                <td><?= $e($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                <td><?= $e($student['class_name']) ?></td>
                                <td><?= $student['grade'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/students/import/commit" class="stack">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= $e($pending['token']) ?>">
                <?php if ($preview->hasInvalidRows() && !$pending['full_import']): ?>
                    <label><input type="checkbox" name="skip_invalid" value="1"> Ungültige Zeilen überspringen und nur gültige Zeilen importieren.</label>
                <?php endif; ?>
                <?php if ($pending['full_import']): ?>
                    <div class="alert alert-error">Beim vollständigen Import werden die oben aufgeführten fehlenden aktiven Schüler deaktiviert. Der Import ist nur möglich, wenn die Vorschau keine ungültigen Zeilen enthält.</div>
                <?php endif; ?>
                <button class="button" type="submit"<?= $pending['full_import'] && $preview->hasInvalidRows() ? ' disabled' : '' ?>>Import verbindlich durchführen</button>
            </form>

            <p class="form-hint">Werden neue Zugangscodes erzeugt, startet nach dem Import unmittelbar ein einmaliger CSV-Download. FachDock speichert anschließend nur die Hashwerte der Codes.</p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
