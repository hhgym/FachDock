<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffRole;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var array<string, string> $form */
/** @var string $success */
/** @var list<array<string, mixed>> $users */
/** @var list<StaffRole> $roles */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formValue = static fn (string $key, string $default = ''): string => $form[$key] ?? $default;
$successMessage = match ($success) {
    'created' => 'Das lokale Benutzerkonto wurde angelegt.',
    'deactivated' => 'Das Benutzerkonto wurde deaktiviert und bestehende Sitzungen wurden beendet.',
    'reactivated' => 'Das Benutzerkonto wurde reaktiviert.',
    'anonymized' => 'Das Benutzerkonto wurde endgültig anonymisiert.',
    'deleted' => 'Das ungenutzte Benutzerkonto wurde endgültig gelöscht.',
    default => '',
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lokale Benutzer · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Lokale Benutzer</div>
    <div class="topbar-actions"><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Administration</span>
        <h1>Lokale Benutzer verwalten</h1>
        <p>Lokale Konten sind für Administration und Schließfachverwaltung vorgesehen. Externe OpenID-Connect-Identitäten werden unabhängig davon verwaltet.</p>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success"><?= $e($successMessage) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Benutzer anlegen</h2>
                <p class="form-hint">Das Konto ist sofort aktiv. Das Anfangspasswort sollte dem Benutzer auf einem sicheren Weg übermittelt und anschließend geändert werden.</p>
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
                    <?php foreach ($roles as $role): ?>
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

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Vorhandene lokale Benutzer</h2>
                <p class="form-hint">Deaktivieren beendet den Zugriff, erhält aber das Konto. Anonymisieren entfernt personenbezogene Kontodaten dauerhaft und erhält nur die technische ID für historische Verweise. Löschen ist nur bei Konten ohne revisionsrelevante Historie möglich.</p>
            </div>
            <span class="badge"><?= count($users) ?> Konten</span>
        </div>

        <?php if ($users === []): ?>
            <p>Keine lokalen Benutzerkonten vorhanden.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th>Angelegt</th><th>Aktionen</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $userId = (int) $user['id'];
                        $isSelf = $userId === $staff->id;
                        $isAnonymized = $user['anonymized_at'] !== null;
                        $isActive = (int) $user['active'] === 1;
                        $role = StaffRole::tryFrom((string) $user['role']);
                        ?>
                        <tr>
                            <td>
                                <strong><?= $e((string) $user['display_name']) ?></strong><?= $isSelf ? ' <span class="badge">aktuelles Konto</span>' : '' ?><br>
                                <small><?= $e((string) $user['username']) ?> · <?= $e((string) $user['email']) ?></small>
                            </td>
                            <td><?= $e($role?->label() ?? (string) $user['role']) ?></td>
                            <td>
                                <?php if ($isAnonymized): ?>
                                    <span class="badge">anonymisiert</span><br><small><?= $e((string) $user['anonymized_at']) ?></small>
                                <?php elseif ($isActive): ?>
                                    <span class="badge">aktiv</span>
                                <?php else: ?>
                                    <span class="badge">deaktiviert</span><?php if ($user['deactivated_at'] !== null): ?><br><small><?= $e((string) $user['deactivated_at']) ?></small><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $user['last_login_at'] !== null ? $e((string) $user['last_login_at']) : '–' ?></td>
                            <td><?= $e((string) $user['created_at']) ?></td>
                            <td>
                                <?php if ($isSelf): ?>
                                    <span class="form-hint">Eigenes aktives Konto ist geschützt.</span>
                                <?php elseif ($isAnonymized): ?>
                                    <span class="form-hint">Endgültig anonymisiert.</span>
                                <?php elseif ($isActive): ?>
                                    <form method="post" action="/admin/users/deactivate">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                                        <button class="button button-secondary" type="submit">Deaktivieren</button>
                                    </form>
                                <?php else: ?>
                                    <div class="stack">
                                        <form method="post" action="/admin/users/reactivate">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <button class="button button-secondary" type="submit">Reaktivieren</button>
                                        </form>
                                        <details>
                                            <summary>Endgültige Stilllegung</summary>
                                            <div class="stack" style="margin-top: .75rem">
                                                <form method="post" action="/admin/users/anonymize" class="stack">
                                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                    <p class="form-hint">Empfohlen bei früher genutzten Konten: Personenbezug entfernen, historische Verweise erhalten.</p>
                                                    <label><input type="checkbox" name="confirm" value="1" required> Anonymisierung ist endgültig</label>
                                                    <button class="button button-secondary" type="submit">Anonymisieren</button>
                                                </form>
                                                <form method="post" action="/admin/users/delete" class="stack">
                                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                    <p class="form-hint">Nur möglich, wenn das Konto noch keine revisionsrelevante Historie besitzt.</p>
                                                    <label><input type="checkbox" name="confirm" value="1" required> Konto endgültig löschen</label>
                                                    <button class="button button-secondary" type="submit">Löschen</button>
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
</main>
</body>
</html>
