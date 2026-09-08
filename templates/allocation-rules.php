<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Booking\AllocationRuleKind;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $rules */
/** @var list<array<string, mixed>> $schoolYears */
/** @var list<array<string, mixed>> $buildings */
/** @var list<array<string, mixed>> $floors */
/** @var list<array<string, mixed>> $areas */
/** @var list<array<string, mixed>> $cabinetGroups */
/** @var int|null $testSchoolYearId */
/** @var int|null $testGrade */
/** @var list<array<string, mixed>> $testResults */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$scopeOptions = [];
foreach ($buildings as $building) {
    $scopeOptions[] = [
        'value' => 'building:' . (int) $building['id'],
        'label' => 'Gebäude · ' . (string) $building['code'] . ' · ' . (string) $building['name'],
    ];
}
foreach ($floors as $floor) {
    $scopeOptions[] = [
        'value' => 'floor:' . (int) $floor['id'],
        'label' => 'Etage · ' . (string) $floor['building_name'] . ' · ' . (string) $floor['code'] . ' · ' . (string) $floor['name'],
    ];
}
foreach ($areas as $area) {
    $scopeOptions[] = [
        'value' => 'area:' . (int) $area['id'],
        'label' => 'Bereich · ' . (string) $area['building_name'] . ' · ' . (string) $area['floor_code'] . ' · ' . (string) $area['code'] . ' · ' . (string) $area['name'],
    ];
}
foreach ($cabinetGroups as $group) {
    $scopeOptions[] = [
        'value' => 'cabinet_group:' . (int) $group['id'],
        'label' => 'Schrankgruppe · ' . (string) $group['floor_code'] . '-' . (string) $group['area_code'] . ' · ' . (string) $group['code'],
    ];
}
$allowedCount = count(array_filter($testResults, static fn (array $row): bool => (bool) $row['rule_allowed']));
$excludedCount = count($testResults) - $allowedCount;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zuteilungsregeln · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Zuteilungsregeln</div>
    <div class="topbar-actions"><a href="/admin/school-years">Schuljahre</a><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungslogik</span>
        <h1>Zuteilungsregeln</h1>
        <p>Verbindliche Freigaben und Ausschlüsse sowie weiche Präferenzen nach Klassenstufe und Standort verwalten.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Neue Regel</h2>
        <?php if ($scopeOptions === []): ?>
            <div class="alert alert-neutral">Vor einer Zuteilungsregel muss mindestens ein Standort angelegt sein.</div>
        <?php else: ?>
            <form method="post" action="/admin/allocation-rules/create" class="grid">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label class="wide">Bezeichnung<input name="name" required maxlength="255" placeholder="Klassen 5/6 im Erdgeschoss"></label>
                <label>Regeltyp
                    <select name="rule_kind" required>
                        <?php foreach (AllocationRuleKind::cases() as $kind): ?>
                            <option value="<?= $e($kind->value) ?>"><?= $e($kind->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Geltungsbereich
                    <select name="scope" required>
                        <?php foreach ($scopeOptions as $option): ?><option value="<?= $e($option['value']) ?>"><?= $e($option['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Klassenstufe von<input name="min_grade" required type="number" min="5" max="12" value="5"></label>
                <label>Klassenstufe bis<input name="max_grade" required type="number" min="5" max="12" value="6"></label>
                <label>Gewichtung<input name="weight" required type="number" min="-1000" max="1000" value="0"><small>Nur bei „Bevorzugt“ wirksam. Höhere Werte werden stärker empfohlen.</small></label>
                <label>Priorität<input name="priority" required type="number" min="1" max="10000" value="100"><small>Kleinere Zahl = frühere Regel.</small></label>
                <label>Gültig ab Schuljahr
                    <select name="valid_from_school_year_id"><option value="">Ohne Untergrenze</option><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>"><?= $e((string) $year['label']) ?></option><?php endforeach; ?></select>
                </label>
                <label>Gültig bis Schuljahr
                    <select name="valid_until_school_year_id"><option value="">Ohne Obergrenze</option><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>"><?= $e((string) $year['label']) ?></option><?php endforeach; ?></select>
                </label>
                <label class="wide">Bemerkung<textarea name="notes" rows="3" maxlength="4000"></textarea></label>
                <label class="check-label"><input type="checkbox" name="active" value="1" checked> Regel aktiv</label>
                <button class="button" type="submit">Regel anlegen</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Regeltest</h2>
        <p class="form-hint">Der Test bewertet die räumlichen Zuteilungsregeln unabhängig von aktueller Belegung oder Reservierungen. Nicht betriebsbereite bzw. manuell nicht buchbare Fächer werden zusätzlich gekennzeichnet.</p>
        <form method="get" action="/admin/allocation-rules" class="grid">
            <label>Schuljahr
                <select name="school_year_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>" <?= $testSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>><?= $e((string) $year['label']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Klassenstufe
                <select name="grade" required><?php for ($grade = 5; $grade <= 12; $grade++): ?><option value="<?= $grade ?>" <?= $testGrade === $grade ? 'selected' : '' ?>>Klasse <?= $grade ?></option><?php endfor; ?></select>
            </label>
            <button class="button" type="submit">Regeln testen</button>
        </form>

        <?php if ($testSchoolYearId !== null && $testGrade !== null): ?>
            <div class="rule-test-summary">
                <span class="badge"><?= $allowedCount ?> regelkonform</span>
                <span class="badge"><?= $excludedCount ?> ausgeschlossen</span>
                <span class="muted">Klasse <?= $testGrade ?> · <?= count($testResults) ?> aktive Fächer geprüft</span>
            </div>
            <?php if ($testResults === []): ?><p>Keine aktiven Schließfächer vorhanden.</p><?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Fach</th><th>Langbezeichnung</th><th>Regel</th><th>Score</th><th>Physisch</th><th>Begründung</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($testResults, 0, 250) as $row): ?>
                            <tr>
                                <td><code><?= $e((string) $row['short_name']) ?></code></td>
                                <td><?= $e((string) $row['long_name']) ?></td>
                                <td><?= (bool) $row['rule_allowed'] ? 'erlaubt' : 'ausgeschlossen' ?></td>
                                <td><?= (int) $row['score'] ?></td>
                                <td><?= (bool) $row['physically_bookable'] ? 'buchbar' : $e((string) $row['operating_status']) ?></td>
                                <td><?= $e((string) ($row['reason'] ?? '–')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($testResults) > 250): ?><p class="form-hint">Zur Übersicht werden die ersten 250 Ergebnisse angezeigt; die Zusammenfassung berücksichtigt alle Fächer.</p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Vorhandene Regeln</h2>
        <?php if ($rules === []): ?><p>Noch keine Zuteilungsregel vorhanden.</p><?php endif; ?>
        <div class="entity-list">
            <?php foreach ($rules as $rule): ?>
                <?php $currentScope = (string) $rule['scope_type'] . ':' . (int) $rule['scope_id']; ?>
                <details class="entity-row">
                    <summary>
                        <?= $e((string) $rule['name']) ?>
                        <span class="status-pill">v<?= (int) $rule['version'] ?></span>
                        <span class="status-pill"><?= $e(AllocationRuleKind::tryFrom((string) $rule['rule_kind'])?->label() ?? (string) $rule['rule_kind']) ?></span>
                        <?php if ((int) $rule['active'] !== 1): ?><span class="status-pill">inaktiv</span><?php endif; ?>
                    </summary>
                    <div class="stack compact-form">
                        <p class="form-hint">Klasse <?= (int) $rule['min_grade'] ?>–<?= (int) $rule['max_grade'] ?> · <?= $e((string) $rule['scope_label']) ?> · Priorität <?= (int) $rule['priority'] ?><?php if ((int) $rule['weight'] !== 0): ?> · Gewicht <?= (int) $rule['weight'] ?><?php endif; ?></p>
                        <?php if ($rule['disabled_years'] !== null): ?><p class="form-hint">Explizit deaktiviert in: <?= $e((string) $rule['disabled_years']) ?></p><?php endif; ?>

                        <form method="post" action="/admin/allocation-rules/update" class="grid">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                            <label class="wide">Bezeichnung<input name="name" required maxlength="255" value="<?= $e((string) $rule['name']) ?>"></label>
                            <label>Regeltyp<select name="rule_kind" required><?php foreach (AllocationRuleKind::cases() as $kind): ?><option value="<?= $e($kind->value) ?>" <?= $kind->value === (string) $rule['rule_kind'] ? 'selected' : '' ?>><?= $e($kind->label()) ?></option><?php endforeach; ?></select></label>
                            <label>Geltungsbereich<select name="scope" required><?php foreach ($scopeOptions as $option): ?><option value="<?= $e($option['value']) ?>" <?= $option['value'] === $currentScope ? 'selected' : '' ?>><?= $e($option['label']) ?></option><?php endforeach; ?></select></label>
                            <label>Klassenstufe von<input name="min_grade" required type="number" min="5" max="12" value="<?= (int) $rule['min_grade'] ?>"></label>
                            <label>Klassenstufe bis<input name="max_grade" required type="number" min="5" max="12" value="<?= (int) $rule['max_grade'] ?>"></label>
                            <label>Gewichtung<input name="weight" required type="number" min="-1000" max="1000" value="<?= (int) $rule['weight'] ?>"></label>
                            <label>Priorität<input name="priority" required type="number" min="1" max="10000" value="<?= (int) $rule['priority'] ?>"></label>
                            <label>Gültig ab<select name="valid_from_school_year_id"><option value="">Ohne Untergrenze</option><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) ($rule['valid_from_school_year_id'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= $e((string) $year['label']) ?></option><?php endforeach; ?></select></label>
                            <label>Gültig bis<select name="valid_until_school_year_id"><option value="">Ohne Obergrenze</option><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) ($rule['valid_until_school_year_id'] ?? 0) === (int) $year['id'] ? 'selected' : '' ?>><?= $e((string) $year['label']) ?></option><?php endforeach; ?></select></label>
                            <label class="wide">Bemerkung<textarea name="notes" rows="3" maxlength="4000"><?= $e((string) ($rule['notes'] ?? '')) ?></textarea></label>
                            <label class="check-label"><input type="checkbox" name="active" value="1" <?= (int) $rule['active'] === 1 ? 'checked' : '' ?>> Regel aktiv</label>
                            <button class="button" type="submit">Änderung speichern</button>
                        </form>

                        <?php if ($schoolYears !== []): ?>
                            <form method="post" action="/admin/allocation-rules/override" class="grid rule-override">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                <label>Abweichung für Schuljahr<select name="school_year_id" required><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>"><?= $e((string) $year['label']) ?></option><?php endforeach; ?></select></label>
                                <label class="check-label"><input type="checkbox" name="enabled" value="1"> In diesem Schuljahr aktiv</label>
                                <button class="button button-secondary" type="submit">Jahresabweichung speichern</button>
                            </form>
                            <p class="form-hint">Ohne explizite Jahresabweichung ist eine global aktive Regel im gültigen Zeitraum aktiv. Nicht angehakt speichert eine explizite Deaktivierung für das gewählte Schuljahr.</p>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
