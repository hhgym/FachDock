<?php
/** @var \FachDock\Auth\AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<array<string,mixed>> $students */
/** @var array<string,int> $counts */
/** @var string $search */
/** @var string $statusFilter */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schülerdaten · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/platform.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell platform-page">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Personen</p>
            <h1>Schülerdaten</h1>
            <p>Stammdaten, Status und vorhandene Zugänge zentral überblicken.</p>
        </div>
        <div class="button-row page-heading-actions">
            <a class="button button-secondary" href="/admin/students">Schülerimport</a>
            <a class="button button-secondary" href="/admin/accounts?type=students">Schülerkonten</a>
        </div>
    </div>

    <section class="compact-metrics compact-metrics-four student-data-metrics" aria-label="Kennzahlen">
        <div class="compact-metric"><span>Gesamt</span><strong><?= (int) $counts['students'] ?></strong></div>
        <div class="compact-metric"><span>Aktiv</span><strong><?= (int) $counts['active_students'] ?></strong></div>
        <div class="compact-metric"><span>Inaktiv</span><strong><?= (int) $counts['inactive_students'] ?></strong></div>
        <div class="compact-metric"><span>Mit Benutzerkonto</span><strong><?= (int) $counts['student_accounts'] ?></strong></div>
    </section>

    <section class="card platform-section filter-card">
        <form method="get" action="/admin/student-data" class="toolbar-form">
            <label>Suche
                <input type="search" name="search" value="<?= $e($search) ?>" placeholder="Name, Matrikelnummer, Klasse oder E-Mail">
            </label>
            <label>Status
                <select name="status">
                    <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>Alle</option>
                    <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Aktiv</option>
                    <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>>Inaktiv</option>
                    <option value="anonymized"<?= $statusFilter === 'anonymized' ? ' selected' : '' ?>>Anonymisiert</option>
                </select>
            </label>
            <button class="button" type="submit">Filtern</button>
            <a class="button button-secondary" href="/admin/student-data">Zurücksetzen</a>
        </form>
    </section>

    <section class="card platform-section">
        <div class="school-year-heading">
            <div>
                <h2>Gespeicherte Schülerdaten</h2>
                <p class="form-hint"><?= count($students) ?> Datensätze in der aktuellen Auswahl.</p>
            </div>
        </div>
        <?php if ($students === []): ?>
            <p class="muted">Keine passenden Schülerdaten gefunden.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Matrikelnummer</th><th>Klasse</th><th>Stufe</th><th>E-Mail</th><th>Stammdaten</th><th>Eltern</th><th>Benutzer</th><th>Aktualisiert</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $anonymized = $student['anonymized_at'] !== null;
                        $active = (int) $student['active'] === 1 && !$anonymized;
                        $accountExists = (int) $student['has_access_code'] === 1 || (int) $student['oidc_count'] > 0;
                        ?>
                        <tr>
                            <td><strong><?= $e(trim((string) $student['first_name'] . ' ' . (string) $student['last_name'])) ?></strong></td>
                            <td><code><?= $e($student['matrikelnummer']) ?></code></td>
                            <td><?= $e($student['class_name']) ?></td>
                            <td><?= (int) $student['grade'] ?></td>
                            <td><?= $student['email'] === null ? '–' : $e($student['email']) ?></td>
                            <td><span class="badge"><?= $anonymized ? 'anonymisiert' : ($active ? 'aktiv' : 'inaktiv') ?></span><?php if ($student['inactive_since'] !== null && !$anonymized): ?><small>seit <?= $e($student['inactive_since']) ?></small><?php endif; ?></td>
                            <td><?= (int) $student['active_parent_links'] ?></td>
                            <td>
                                <?php if ($accountExists): ?>
                                    <a class="button button-secondary button-small" href="/admin/accounts?type=students&amp;student_id=<?= (int) $student['id'] ?>">Benutzer öffnen</a>
                                <?php else: ?>
                                    <span class="muted">Kein Benutzer</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $e($student['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
