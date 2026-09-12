<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffRole;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var string $type */
/** @var string $search */
/** @var string $statusFilter */
/** @var int|null $studentId */
/** @var list<array<string,mixed>> $students */
/** @var list<array<string,mixed>> $parents */
/** @var list<array<string,mixed>> $localUsers */
/** @var list<StaffRole> $localRoles */
/** @var array<string,string> $localForm */
/** @var array<string,int> $settings */
/** @var array<string,int> $preview */
/** @var list<string> $errors */
/** @var string $saved */
$localUsers = $localUsers ?? [];
$localRoles = $localRoles ?? StaffRole::cases();
$localForm = $localForm ?? [];
$settings = $settings ?? [];
$preview = $preview ?? [];
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formValue = static fn (string $key, string $default = ''): string => $localForm[$key] ?? $default;
$notice = match ($saved) {
    'student_deactivated' => 'Der Schüleraccount wurde deaktiviert.',
    'student_reactivated' => 'Der Schüleraccount wurde reaktiviert.',
    'student_anonymized' => 'Der Schüleraccount wurde dauerhaft anonymisiert.',
    'parent_deactivated' => 'Das Elternkonto wurde deaktiviert.',
    'parent_reactivated' => 'Das Elternkonto wurde reaktiviert.',
    'parent_anonymized' => 'Das Elternkonto wurde dauerhaft anonymisiert.',
    'lifecycle_run' => 'Der Account-Lifecycle wurde ausgeführt.',
    'created' => 'Das lokale Benutzerkonto wurde angelegt.',
    'deactivated' => 'Das lokale Benutzerkonto wurde deaktiviert und bestehende Sitzungen wurden beendet.',
    'reactivated' => 'Das lokale Benutzerkonto wurde reaktiviert.',
    'anonymized' => 'Das lokale Benutzerkonto wurde endgültig anonymisiert.',
    'deleted' => 'Das ungenutzte lokale Benutzerkonto wurde endgültig gelöscht.',
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
    <link rel="stylesheet" href="/assets/platform.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner"><strong>FachDock</strong></div></header>
<main class="page-shell platform-page">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Personen</p>
            <h1>Benutzerkonten</h1>
            <p>Zugänge für Schülerinnen und Schüler, Eltern sowie lokale Mitarbeitende verwalten.</p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= $e($error) ?></div><?php endforeach; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= $e($notice) ?></div><?php endif; ?>

    <section class="card platform-section account-tabs-card">
        <div class="button-row account-tabs" role="navigation" aria-label="Kontotyp">
            <a class="button <?= $type === 'students' ? '' : 'button-secondary' ?>" href="/admin/accounts?type=students">Schülerkonten</a>
            <a class="button <?= $type === 'parents' ? '' : 'button-secondary' ?>" href="/admin/accounts?type=parents">Elternkonten</a>
            <a class="button <?= $type === 'local' ? '' : 'button-secondary' ?>" href="/admin/accounts?type=local">Lokale Benutzer</a>
        </div>
    </section>

    <?php if ($type === 'local'): ?>
        <section class="card platform-section stack">
            <div class="school-year-heading">
                <div>
                    <h2>Lokalen Benutzer anlegen</h2>
                    <p class="form-hint">Lokale Konten sind für Administration und Schließfachverwaltung vorgesehen. Das Anfangspasswort sollte auf einem sicheren Weg übermittelt werden.</p>
                </div>
                <span class="badge">lokales Konto</span>
            </div>
            <form method="post" action="/admin/users/create" class="grid">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label>Benutzername
                    <input type="text" name="username" value="<?= $e($formValue('username')) ?>" minlength="3" maxlength="100" required autocomplete="off">
                </label>
                <label>Anzeigename
                    <input type="text" name="display_name" value="<?= $e($formValue('display_name')) ?>" maxlength="255" required autocomplete="off">
                </label>
                <label>E-Mail-Adresse
                    <input type="email" name="email" value="<?= $e($formValue('email')) ?>" maxlength="255" required autocomplete="off">
                </label>
                <label>Rolle
                    <select name="role" required>
                        <?php foreach ($localRoles as $role): ?>
                            <option value="<?= $e($role->value) ?>" <?= $formValue('role', StaffRole::LockerManager->value) === $role->value ? 'selected' : '' ?>><?= $e($role->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Anfangspasswort
                    <input type="password" name="password" minlength="12" required autocomplete="new-password">
                </label>
                <label>Passwort wiederholen
                    <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password">
                </label>
                <button class="button" type="submit">Benutzer anlegen</button>
            </form>
        </section>

        <section class="card platform-section">
            <div class="school-year-heading">
                <div>
                    <h2>Lokale Benutzer</h2>
                    <p class="form-hint"><?= count($localUsers) ?> Konto/Konten. Deaktivieren erhält das Konto; Anonymisieren entfernt den Personenbezug dauerhaft.</p>
                </div>
            </div>
            <?php if ($localUsers === []): ?>
                <p class="muted">Keine lokalen Benutzerkonten vorhanden.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th>Angelegt</th><th>Aktionen</th></tr></thead>
                        <tbody>
                        <?php foreach ($localUsers as $user): ?>
                            <?php
                            $userId = (int) $user['id'];
                            $isSelf = $userId === $staff->id;
                            $isAnonymized = $user['anonymized_at'] !== null;
                            $isActive = (int) $user['active'] === 1;
                            $role = StaffRole::tryFrom((string) $user['role']);
                            ?>
                            <tr>
                                <td><strong><?= $e($user['display_name']) ?></strong><?= $isSelf ? ' <span class="badge">aktuelles Konto</span>' : '' ?><small><?= $e($user['username']) ?> · <?= $e($user['email']) ?></small></td>
                                <td><?= $e($role?->label() ?? (string) $user['role']) ?></td>
                                <td>
                                    <?php if ($isAnonymized): ?><span class="badge">anonymisiert</span>
                                    <?php elseif ($isActive): ?><span class="badge">aktiv</span>
                                    <?php else: ?><span class="badge">deaktiviert</span><?php endif; ?>
                                </td>
                                <td><?= $user['last_login_at'] !== null ? $e($user['last_login_at']) : '–' ?></td>
                                <td><?= $e($user['created_at']) ?></td>
                                <td>
                                    <?php if ($isSelf): ?>
                                        <span class="form-hint">Eigenes aktives Konto ist geschützt.</span>
                                    <?php elseif ($isAnonymized): ?>
                                        <span class="form-hint">Endgültig anonymisiert.</span>
                                    <?php elseif ($isActive): ?>
                                        <form method="post" action="/admin/users/deactivate">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <button class="button button-secondary button-small" type="submit">Deaktivieren</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="button-row">
                                            <form method="post" action="/admin/users/reactivate">
                                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                <button class="button button-secondary button-small" type="submit">Reaktivieren</button>
                                            </form>
                                            <details class="inline-details">
                                                <summary class="button button-secondary button-small">Endgültig stilllegen</summary>
                                                <div class="details-panel stack">
                                                    <form method="post" action="/admin/users/anonymize" class="stack">
                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                        <label><input type="checkbox" name="confirm" value="1" required> Anonymisierung ist endgültig</label>
                                                        <button class="button button-secondary button-small" type="submit">Anonymisieren</button>
                                                    </form>
                                                    <form method="post" action="/admin/users/delete" class="stack">
                                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                        <label><input type="checkbox" name="confirm" value="1" required> Konto endgültig löschen</label>
                                                        <button class="button button-secondary button-small" type="submit">Löschen</button>
                                                    </form>
                                                </div>
                                            </details>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card platform-section account-lifecycle-summary">
            <div>
                <h2>Account-Lifecycle</h2>
                <p class="muted">Schüler: nach <?= (int) $settings['student_deactivation_days'] ?> Tagen deaktivieren, nach <?= (int) $settings['student_anonymization_days'] ?> Tagen anonymisieren. Eltern: nach <?= (int) $settings['parent_deactivation_days'] ?> Tagen deaktivieren, nach <?= (int) $settings['parent_anonymization_days'] ?> Tagen anonymisieren, sobald kein aktives Kind mehr verknüpft ist. <a href="/admin/privacy">Fristen konfigurieren</a>.</p>
            </div>
            <div class="compact-metrics compact-metrics-four">
                <div class="compact-metric"><span>Schüler · Sperre</span><strong><?= (int) $preview['students_to_deactivate'] ?></strong></div>
                <div class="compact-metric"><span>Schüler · Anonym.</span><strong><?= (int) $preview['students_to_anonymize'] ?></strong></div>
                <div class="compact-metric"><span>Eltern · Sperre</span><strong><?= (int) $preview['parents_to_deactivate'] ?></strong></div>
                <div class="compact-metric"><span>Eltern · Anonym.</span><strong><?= (int) $preview['parents_to_anonymize'] ?></strong></div>
            </div>
            <form method="post" action="/admin/accounts/lifecycle/run">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <button class="button button-secondary button-small" type="submit">Fällige Aktionen ausführen</button>
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
                <div class="school-year-heading"><div><h2>Schülerkonten</h2><p><?= count($students) ?> Konto/Konten.</p></div><a class="button button-secondary button-small" href="/admin/student-data">Schülerdaten</a></div>
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
                                    <details class="inline-details"><summary class="button button-danger button-small">Sofort anonymisieren</summary><div class="details-panel"><form method="post" action="/admin/accounts/student/anonymize" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><label>Zur Bestätigung <code>ANONYMISIEREN <?= (int) $account['id'] ?></code> eingeben<input type="text" name="confirmation" autocomplete="off" required></label><button class="button button-danger button-small" type="submit">Unwiderruflich anonymisieren</button></form></div></details>
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
                <div class="school-year-heading"><div><h2>Elternkonten</h2><p><?= count($parents) ?> Konto/Konten.</p></div><a class="button button-secondary button-small" href="/admin/parents">Elternkontakte</a></div>
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
                                    <details class="inline-details"><summary class="button button-danger button-small">Sofort anonymisieren</summary><div class="details-panel"><form method="post" action="/admin/accounts/parent/anonymize" class="stack"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>"><label>Zur Bestätigung <code>ANONYMISIEREN <?= (int) $account['id'] ?></code> eingeben<input type="text" name="confirmation" autocomplete="off" required></label><button class="button button-danger button-small" type="submit">Unwiderruflich anonymisieren</button></form></div></details>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
