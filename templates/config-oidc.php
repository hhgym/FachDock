<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var array<string, mixed> $settings */
/** @var list<array<string, mixed>> $identities */
/** @var list<string> $errors */
/** @var bool $saved */
/** @var array<string, string>|null $testResult */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IServ / OpenID Connect · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · IServ / OpenID Connect</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Konfiguration</span>
        <h1>IServ / OpenID Connect</h1>
        <p>Schüler und Lehrkräfte können sich über den schulischen IServ anmelden. Administration und Schließfachverwaltung bleiben lokale FachDock-Konten.</p>
    </header>

    <?php if ($saved): ?><div class="alert alert-success">OIDC-Konfiguration gespeichert.</div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($testResult !== null): ?>
        <div class="alert alert-success">
            <strong>Discovery erfolgreich.</strong><br>
            Issuer: <?= $e($testResult['issuer']) ?><br>
            Authorization: <?= $e($testResult['authorization_endpoint']) ?><br>
            Token: <?= $e($testResult['token_endpoint']) ?><br>
            UserInfo: <?= $e($testResult['userinfo_endpoint']) ?>
        </div>
    <?php endif; ?>

    <form class="card stack" method="post" action="/admin/config/oidc">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>> IServ-Anmeldung aktivieren</label>
        <div class="grid">
            <label>Issuer / IServ-Adresse
                <input name="issuer" type="url" value="<?= $e((string) $settings['issuer']) ?>" placeholder="https://iserv.example.schule">
            </label>
            <label>Client-ID
                <input name="client_id" value="<?= $e((string) $settings['client_id']) ?>" autocomplete="off">
            </label>
            <label>Client-Geheimnis
                <input name="client_secret" type="password" value="" autocomplete="new-password" placeholder="leer = vorhandenes Geheimnis beibehalten">
                <small><?= !empty($settings['secret_present']) ? 'Ein Geheimnis ist gespeichert.' : 'Noch kein Geheimnis gespeichert.' ?></small>
            </label>
            <label>Sitzungsdauer (Minuten)
                <input name="session_lifetime_minutes" type="number" min="15" max="10080" value="<?= (int) $settings['session_lifetime_minutes'] ?>">
            </label>
        </div>
        <label>Scopes
            <input name="scopes" value="<?= $e((string) $settings['scopes']) ?>">
            <small>Empfohlen: openid profile email iserv:uuid iserv:groups iserv:roles</small>
        </label>
        <label>Automatische Schülerzuordnung
            <select name="student_auto_match">
                <option value="none" <?= $settings['student_auto_match'] === 'none' ? 'selected' : '' ?>>keine</option>
                <option value="email" <?= $settings['student_auto_match'] === 'email' ? 'selected' : '' ?>>IServ-E-Mail = Schüler-E-Mail</option>
                <option value="username_to_matrikelnummer" <?= $settings['student_auto_match'] === 'username_to_matrikelnummer' ? 'selected' : '' ?>>IServ-Benutzername = Matrikelnummer</option>
            </select>
        </label>
        <label>IServ-Rollen für Lehrkräfte
            <input name="teacher_role_names" value="<?= $e((string) $settings['teacher_role_names']) ?>" placeholder="Lehrer, Lehrkräfte">
            <small>Kommagetrennte Werte aus dem Claim <code>iserv:roles</code>.</small>
        </label>
        <label>Callback-URL
            <input value="<?= $e((string) $settings['callback_url']) ?>" readonly>
            <small>Diese URL muss im IServ-SSO-Client als Redirect-URI eingetragen werden.</small>
        </label>
        <div class="cluster">
            <button class="button" type="submit">Speichern</button>
            <a class="button button-secondary" href="/admin/config">Zur Konfiguration</a>
        </div>
    </form>

    <form class="card stack" method="post" action="/admin/config/oidc/test">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <h2>Verbindung testen</h2>
        <p>Prüft Discovery-Dokument, Issuer und die sicherheitsrelevanten HTTPS-Endpunkte der aktuell gespeicherten Konfiguration. Es wird dabei keine Benutzeranmeldung gestartet.</p>
        <button class="button button-secondary" type="submit">OIDC-Discovery testen</button>
    </form>

    <section class="card stack">
        <h2>Bekannte IServ-Identitäten</h2>
        <p class="form-hint">Automatisch nicht eindeutig zuordenbare Konten bleiben auf „Nicht zugeordnet“. Eine manuelle Zuordnung ist jederzeit möglich.</p>
        <div class="table-scroll"><table>
            <thead><tr><th>IServ-Konto</th><th>Typ</th><th>Zuordnung</th><th>Letzte Anmeldung</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach ($identities as $identity): ?>
                <tr>
                    <td>
                        <strong><?= $e((string) ($identity['display_name'] ?? $identity['account_name'] ?? 'IServ-Konto')) ?></strong><br>
                        <small><?= $e((string) ($identity['account_name'] ?? '')) ?><?= !empty($identity['email']) ? ' · ' . $e((string) $identity['email']) : '' ?></small>
                    </td>
                    <td><?= $e(match ((string) $identity['identity_type']) {
                        'student' => 'Schüler',
                        'teacher' => 'Lehrkraft',
                        default => 'Nicht zugeordnet',
                    }) ?><?= empty($identity['active']) ? ' · deaktiviert' : '' ?></td>
                    <td><?= !empty($identity['student_id']) ? $e((string) $identity['student_name'] . ' · ' . (string) $identity['class_name'] . ' · ' . (string) $identity['matrikelnummer']) : '—' ?></td>
                    <td><?= $e((string) ($identity['last_login_at'] ?? '—')) ?></td>
                    <td><div class="stack">
                        <form method="post" action="/admin/config/oidc/identity">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                            <input type="hidden" name="action" value="teacher">
                            <button class="button button-secondary" type="submit">Als Lehrkraft</button>
                        </form>
                        <form method="post" action="/admin/config/oidc/identity" class="cluster">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                            <input type="hidden" name="action" value="student">
                            <input name="matrikelnummer" placeholder="Matrikelnummer" required>
                            <button class="button button-secondary" type="submit">Schüler zuordnen</button>
                        </form>
                        <form method="post" action="/admin/config/oidc/identity">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                            <input type="hidden" name="action" value="<?= empty($identity['active']) ? 'enable' : 'disable' ?>">
                            <button class="button button-secondary" type="submit"><?= empty($identity['active']) ? 'Aktivieren' : 'Deaktivieren' ?></button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($identities === []): ?><tr><td colspan="5">Noch keine IServ-Anmeldung protokolliert.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
</main>
</body>
</html>
