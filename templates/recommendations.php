<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $students */
/** @var list<array<string, mixed>> $schoolYears */
/** @var int|null $selectedStudentId */
/** @var int|null $selectedSchoolYearId */
/** @var array<string, mixed>|null $selectedStudent */
/** @var list<array<string, mixed>> $recommended */
/** @var list<array<string, mixed>> $available */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fachempfehlungen · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Fachempfehlungen</div>
    <div class="topbar-actions"><a href="/admin/allocation-rules">Zuteilungsregeln</a><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungslogik</span>
        <h1>Fachempfehlungen prüfen</h1>
        <p>Zeigt die aktuell regelkonformen, freien und technisch buchbaren Schließfächer für einen Schüler.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Vorschau</h2>
        <p class="form-hint">Administratoren können auch ein noch nicht für reguläre Neubuchungen geöffnetes zukünftiges Schuljahr prüfen. Geschlossene Schuljahre bleiben ausgeschlossen.</p>
        <form method="get" action="/admin/recommendations" class="grid">
            <label>Schüler
                <select name="student_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                            <?= $e((string) $student['class_name']) ?> · <?= $e((string) $student['last_name']) ?>, <?= $e((string) $student['first_name']) ?> · <?= $e((string) $student['matrikelnummer']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Schuljahr
                <select name="school_year_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>><?= $e((string) $year['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" type="submit">Empfehlungen berechnen</button>
        </form>
    </section>

    <?php if ($selectedStudent !== null && $selectedSchoolYearId !== null): ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow">Ergebnis</span>
                    <h2><?= $e((string) $selectedStudent['first_name']) ?> <?= $e((string) $selectedStudent['last_name']) ?></h2>
                    <p class="form-hint"><?= $e((string) $selectedStudent['class_name']) ?> · Klassenstufe <?= (int) $selectedStudent['grade'] ?> · <?= count($available) ?> aktuell verfügbare Fächer</p>
                </div>
                <span class="badge"><?= count($recommended) ?> Empfehlungen</span>
            </div>

            <?php if ($recommended === []): ?>
                <div class="alert alert-neutral">Für die Auswahl gibt es derzeit kein regelkonformes und verfügbares Schließfach.</div>
            <?php else: ?>
                <div class="recommendation-grid">
                    <?php foreach ($recommended as $index => $locker): ?>
                        <article class="recommendation-card">
                            <span class="eyebrow">Empfehlung <?= $index + 1 ?></span>
                            <h3><code><?= $e((string) $locker['short_name']) ?></code></h3>
                            <p><?= $e((string) $locker['floor_code']) ?> · Bereich <?= $e((string) $locker['area_code']) ?> · Gruppe <?= $e((string) $locker['group_code']) ?></p>
                            <p class="form-hint">Soft-Score <?= (int) $locker['score'] ?><?php if ((bool) $locker['barrier_friendly']): ?> · barrierearm<?php endif; ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card stack">
            <h2>Alle verfügbaren Fächer</h2>
            <p class="form-hint">Vollständige Auswahl nach Soft-Score; bei gleichem Score alphabetisch zur Kontrolle. Die drei Empfehlungen oben werden innerhalb gleicher Score-Gruppen bewusst zufällig verteilt.</p>
            <?php if ($available === []): ?>
                <p>Keine verfügbaren Fächer.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Fach</th><th>Langbezeichnung</th><th>Etage</th><th>Bereich</th><th>Gruppe</th><th>Score</th><th>Merkmal</th></tr></thead>
                        <tbody>
                        <?php foreach ($available as $locker): ?>
                            <tr>
                                <td><code><?= $e((string) $locker['short_name']) ?></code></td>
                                <td><?= $e((string) $locker['long_name']) ?></td>
                                <td><?= $e((string) $locker['floor_code']) ?></td>
                                <td><?= $e((string) $locker['area_code']) ?> · <?= $e((string) $locker['area_name']) ?></td>
                                <td><?= $e((string) $locker['group_code']) ?></td>
                                <td><?= (int) $locker['score'] ?></td>
                                <td><?= (bool) $locker['barrier_friendly'] ? 'barrierearm' : '–' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
