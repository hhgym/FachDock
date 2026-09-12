<?php
/** @var \FachDock\Auth\AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var string $type */
/** @var string $search */
/** @var string $statusFilter */
/** @var int|null $studentId */
/** @var list<array<string,mixed>> $students */
/** @var list<array<string,mixed>> $parents */
/** @var array<string,int> $counts */
/** @var array<string,int> $settings */
/** @var array<string,int> $preview */
/** @var list<string> $errors */
/** @var string $saved */
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$notice = match ($saved) {
    'student_deactivated' => 'Der Schüleraccount wurde deaktiviert.',
    'student_reactivated' => 'Der Schüleraccount wurde reaktiviert.',
    'student_anonymized' => 'Der Schüleraccount wurde dauerhaft anonymisiert.',
    'parent_deactivated' => 'Das Elternkonto wurde deaktiviert.',
    'parent_reactivated' => 'Das Elternkonto wurde reaktiviert.',
    'parent_anonymized' => 'Das Elternkonto wurde dauerhaft anonymisiert.',
    'lifecycle_run' => 'Der Account-Lifecycle wurde ausgeführt.',
    default => '',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzerkonten · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell platform-page">
    <div class="page-heading">
        <div><p class="eyebrow">Personen</p><h1>Benutzerkonten</h1><p>Zugänge für Schülerinnen und Schüler sowie Eltern verwalten.</p></div>
        <a class="button button-secondary" href="/admin/users">Lokale Benutzer</a>
    </div>

    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= $e($error) ?></div><?php endforeach; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= $e($notice) ?></div><?php endif; ?>

    <section class="card platform-section">
        <div class="button-row">
            <a class="button <?= $type === 'students' ? '' : 'button-secondary' ?>" href="/admin/accounts?type=students">Schülerkonten</a>
            <a class="button <?= $type === 'parents' ? '' : 'button-secondary' ?>" href="/admin/accounts?type=parents">Elternkonten</a>
        </div>
        <p class="muted">Schüler: nach <?= (int) $settings['student_deactivation_days'] ?> Tagen deaktivieren, nach <?= (int) $settings['student_anonymization_days'] ?> Tagen anonymisieren. Eltern: nach <?= (int) $settings['parent_deactivation_days'] ?> Tagen deaktivieren, nach <?= (int) $settings['parent_anonymization_days'] ?> Tagen anonymisieren, sobald kein aktives Kind mehr verknüpft ist. <a href="/admin/privacy">Fristen konfigurieren</a>.</p>
        <div class="privacy-preview">
            <div><span>Schüler fällig: Sperre</span><strong><?= (int) $preview['students_to_deactivate'] ?></strong></div>
            <div><span>Schüler fällig: Anonymisierung</span><strong><?= (int) $preview['students_to_anonymize'] ?></strong></div>
            <div><span>Eltern fällig: Sperre</span><strong><?= (int) $preview['parents_to_deactivate'] ?></strong></div>
            <div><span>Eltern fällig: Anonymisierung</span><strong><?= (int) $preview['parents_to_anonymize'] ?></strong></div>
        </div>
        <form method="post" action="/admin/accounts/lifecycle/run">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="button button-secondary" type="submit">Fällige Lifecycle-Aktionen jetzt ausführen</button>
        </form>
    </section>

    <section class="card platform-section">
        <form method="get" action="/admin/accounts" class="toolbar-form">
            <input type="hidden" name="type" value="<?= $e($type) ?>">
            <label>Suche
                <input type="search" name="search" value="<?= $e($search) ?>" placeholder="Name, E-Mail, Matrikelnummer oder Klasse">
            </label>
            <label>Kontostatus
                <select name="status">
                    <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>Alle</option>
                    <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Aktiv</option>
                    <option value="waiting"<?= $statusFilter === 'waiting' ? ' selected' : '' ?>>Nachlauf</option>
                    <option value="deactivated"<?= $statusFilter === 'deactivated' ? ' selected' : '' ?>>Deaktiviert</option>
                    <option value="anonymized"<?= $statusFilter === 'anonymized' ? ' selected' : '' ?>>Anonymisiert</option>
                </select>
            </label>
            <button class="button" type="submit">Filtern</button>
            <a class="button button-secondary" href="/admin/accounts?type=<?= $e($type) ?>">Zurücksetzen</a>
        </form>
    </section>

    <?php if ($type === 'students'): ?>
        <section class="card platform-section">
            <div class="school-year-heading"><div><h2>Schülerkonten</h2><p><?= count($students) ?> Konto/Konten.</p></div><a class="button button-secondary" href="/admin/student-data">Schülerdaten</a></div>
            <?php if ($students === []): ?><p class="muted">Keine passenden Schülerkonten gefunden.</p><?php else: ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Schüler</th><th>Zugänge</th><th>Kontostatus</th><th>Letzter Login</th><th>Nächste Fristen</th><th>Aktionen</th></tr></thead><tbody>
            <?php foreach ($students as $account): ?>
                <?php
                $anonymized = $account['anonymized_at'] !== null;
                $deactivated = $account['account_deactivated_at'] !== null && !$anonymized;
                $waiting = (int) $account['active'] === 0 && !$deactivated && !$anonymized;
                $label = $anonymized ? 'anonymisiert' : ($deactivated ? 'deaktiviert' : ($waiting ? 'Nachlauf' : 'aktiv'));
                ?>
                <tr>
                    <td><strong><?= $e(trim((string) $account['first_name'] . ' ' . (string) $account['last_name'])) ?></strong><small><?= $e($account['class_name']) ?> · <?= $e($account['matrikelnummer']) ?></small></td>
                    <td><?= (int) $account['oidc_count'] > 0 ? 'OpenID Connect' : '' ?><?= (int) $account['oidc_count'] > 0 && (int) $account['has_access_code'] === 1 ? ' + ' : '' ?><?= (int) $account['has_access_code'] === 1 ? 'Zugangscode' : '' ?></td>
                    <td><span class="badge"><?= $e($label) ?></span><?php if ($account['account_deactivation_source'] !== null): ?><small><?= $account['account_deactivation_source'] === 'manual' ? 'manuell' : 'automatisch' ?></small><?php endif; ?></td>
                    <td><?= $account['last_login_at'] === null ? '–' : $e($account['last_login_at']) ?></td>
                    <td><?php if ($account['inactive_since'] === null): ?>–<?php else: ?><small>Sperre: <?= $e($account['deactivation_due_at']) ?><br>Anonym.: <?= $e($account['anonymization_due_at']) ?></small><?php endif; ?></td>
                    <td>
                        <div class="button-row">
                            <?php if (!$anonymized && !$deactivated): ?>
                                <form method="post" action="/admin/accounts/student/deactivate"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><button class="button button-secondary button-small" type="submit">Deaktivieren</button></form>
                            <?php elseif ($deactivated): ?>
                                <form method="post" action="/admin/accounts/student/reactivate"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><button class="button button-secondary button-small" type="submit">Reaktivieren</button></form>
                            <?php endif; ?>
                            <?php if (!$anonymized): ?>
                                <details><summary class="button button-danger button-small">Sofort anonymisieren</summary><form method="post" action="/admin/accounts/student/anonymize" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><label>Zur Bestätigung <code>ANONYMISIEREN <?= (int) $account['id'] ?></code> eingeben<input type="text" name="confirmation" autocomplete="off" required></label><button class="button button-danger button-small" type="submit">Unwiderruflich anonymisieren</button></form></details>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card platform-section">
            <div class="school-year-heading"><div><h2>Elternkonten</h2><p><?= count($parents) ?> Konto/Konten.</p></div><a class="button button-secondary" href="/admin/parents">Elternkontakte</a></div>
            <?php if ($parents === []): ?><p class="muted">Keine passenden Elternkonten gefunden.</p><?php else: ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Elternkonto</th><th>Kinder</th><th>Kontostatus</th><th>Letzter Login</th><th>Nächste Fristen</th><th>Aktionen</th></tr></thead><tbody>
            <?php foreach ($parents as $account): ?>
                <?php
                $anonymized = $account['anonymized_at'] !== null || $account['status'] === 'anonymized';
                $deactivated = (int) $account['active'] === 0 && !$anonymized;
                $waiting = (int) $account['active'] === 1 && $account['lifecycle_started_at'] !== null && !$anonymized;
                $label = $anonymized ? 'anonymisiert' : ($deactivated ? 'deaktiviert' : ($waiting ? 'Nachlauf' : 'aktiv'));
                ?>
                <tr>
                    <td><strong><?= $e(trim((string) ($account['first_name'] ?? '') . ' ' . (string) ($account['last_name'] ?? ''))) ?></strong><small><?= $e($account['email']) ?></small></td>
                    <td><?= (int) $account['active_children'] ?> aktiv · <?= (int) $account['linked_students_total'] ?> gesamt</td>
                    <td><span class="badge"><?= $e($label) ?></span><?php if ($account['deactivation_source'] !== null): ?><small><?= $account['deactivation_source'] === 'manual' ? 'manuell' : 'automatisch' ?></small><?php endif; ?></td>
                    <td><?= $account['last_login_at'] === null ? '–' : $e($account['last_login_at']) ?></td>
                    <td><?php if ($account['lifecycle_started_at'] === null): ?>–<?php else: ?><small>Sperre: <?= $e($account['deactivation_due_at']) ?><br>Anonym.: <?= $e($account['anonymization_due_at']) ?></small><?php endif; ?></td>
                    <td>
                        <div class="button-row">
                            <?php if (!$anonymized && !$deactivated): ?>
                                <form method="post" action="/admin/accounts/parent/deactivate"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><button class="button button-secondary button-small" type="submit">Deaktivieren</button></form>
                            <?php elseif ($deactivated): ?>
                                <form method="post" action="/admin/accounts/parent/reactivate"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><button class="button button-secondary button-small" type="submit">Reaktivieren</button></form>
                            <?php endif; ?>
                            <?php if (!$anonymized): ?>
                                <details><summary class="button button-danger button-small">Sofort anonymisieren</summary><form method="post" action="/admin/accounts/parent/anonymize" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><label>Zur Bestätigung <code>ANONYMISIEREN <?= (int) $account['id'] ?></code> eingeben<input type="text" name="confirmation" autocomplete="off" required></label><button class="button button-danger button-small" type="submit">Unwiderruflich anonymisieren</button></form></details>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
