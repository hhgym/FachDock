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
/** @var array<string, mixed>|null $loginTestResult */
$loginTestResult = $loginTestResult ?? null;
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$claimValue = static function (mixed $value): string {
    if (is_string($value)) {
        return $value;
    }
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return is_string($encoded) ? $encoded : '[nicht darstellbar]';
};
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
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>> IServ-Anmeldung produktiv aktivieren</label>
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
            <small>Grundlage: <code>openid profile email iserv:uuid iserv:groups iserv:roles</code>. Für den IServ-Claim <code>untis_username</code> zusätzlich <code>iserv:untis</code> anfordern und im IServ-Client freigeben.</small>
        </label>

        <div class="card stack">
            <div>
                <span class="eyebrow">Automatische Zuordnung</span>
                <h2>Schüler</h2>
            </div>
            <label>IServ-Rollen für Schüler
                <input name="student_role_names" value="<?= $e((string) ($settings['student_role_names'] ?? '')) ?>" placeholder="z. B. Schüler">
                <small>Kommagetrennte Werte aus <code>iserv:roles</code>. Ist das Feld leer, wird die Rolle nicht als zusätzliche Bedingung geprüft.</small>
            </label>
            <div class="grid">
                <label>OIDC-Claim zur Zuordnung
                    <input name="student_match_claim" value="<?= $e((string) ($settings['student_match_claim'] ?? 'email')) ?>" placeholder="z. B. untis_username">
                    <small>Beispiel: <code>untis_username</code>. Welche Claims IServ tatsächlich liefert, zeigt die Testanmeldung unten.</small>
                </label>
                <label>FachDock-Zielfeld
                    <select name="student_match_field">
                        <option value="none" <?= ($settings['student_match_field'] ?? 'email') === 'none' ? 'selected' : '' ?>>keine automatische Zuordnung</option>
                        <option value="email" <?= ($settings['student_match_field'] ?? 'email') === 'email' ? 'selected' : '' ?>>Schüler-E-Mail</option>
                        <option value="matrikelnummer" <?= ($settings['student_match_field'] ?? 'email') === 'matrikelnummer' ? 'selected' : '' ?>>Matrikelnummer</option>
                    </select>
                    <small>Für IServ/Untis kann z. B. <code>untis_username → Matrikelnummer</code> verwendet werden. Bitte über die Testanmeldung prüfen, ob der übertragene Wert tatsächlich der Matrikelnummer entspricht.</small>
                </label>
            </div>
        </div>

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

    <section class="grid">
        <form class="card stack" method="post" action="/admin/config/oidc/test">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <h2>Verbindung testen</h2>
            <p>Prüft Discovery-Dokument, Issuer und die sicherheitsrelevanten HTTPS-Endpunkte der gespeicherten Konfiguration. Die produktive IServ-Anmeldung muss dafür noch nicht aktiviert sein.</p>
            <button class="button button-secondary" type="submit">OIDC-Discovery testen</button>
        </form>

        <form class="card stack" method="post" action="/admin/config/oidc/login-test">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <h2>Anmeldung testen</h2>
            <p>Startet eine echte OIDC-Anmeldung mit den gespeicherten Scopes. Danach zeigt FachDock die vom UserInfo-Endpunkt übertragenen Claims an.</p>
            <p class="form-hint">Der Test legt keine OIDC-Identität an, erzeugt keine FachDock-Benutzersitzung und verändert keine Schülerzuordnung.</p>
            <button class="button button-secondary" type="submit">Testanmeldung mit IServ starten</button>
        </form>
    </section>

    <?php if ($loginTestResult !== null): ?>
        <section class="card stack">
            <div>
                <span class="eyebrow">Testanmeldung erfolgreich</span>
                <h2>Von IServ übertragene Daten</h2>
                <p class="form-hint">Angezeigt werden die Claims, die der konfigurierte UserInfo-Endpunkt für diese Testanmeldung tatsächlich zurückgegeben hat. Access- und ID-Tokens werden nicht angezeigt oder gespeichert.</p>
            </div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Claim</th><th>Übertragener Wert</th></tr></thead>
                    <tbody>
                    <?php foreach ($loginTestResult as $claim => $value): ?>
                        <tr>
                            <td><code><?= $e((string) $claim) ?></code></td>
                            <td><code><?= nl2br($e($claimValue($value))) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($loginTestResult === []): ?><tr><td colspan="2">IServ hat keine Claims zurückgegeben.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="card stack">
        <h2>Bekannte IServ-Identitäten</h2>
        <p class="form-hint">Automatische Zuordnungen werden bei jeder IServ-Anmeldung erneut geprüft. Manuelle Zuordnungen bleiben bestehen, bis wieder auf Automatik umgestellt wird.</p>
        <div class="table-scroll"><table class="data-table">
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
                    }) ?> · <?= ($identity['assignment_source'] ?? 'automatic') === 'manual' ? 'manuell' : 'automatisch' ?><?= empty($identity['active']) ? ' · deaktiviert' : '' ?></td>
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
                        <?php if (($identity['assignment_source'] ?? 'automatic') === 'manual'): ?>
                            <form method="post" action="/admin/config/oidc/identity">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="identity_id" value="<?= (int) $identity['id'] ?>">
                                <input type="hidden" name="action" value="automatic">
                                <button class="button button-secondary" type="submit">Automatik verwenden</button>
                            </form>
                        <?php endif; ?>
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

    <section class="card stack">
        <h2>Spätere Erweiterung: Eltern</h2>
        <p>Eine OIDC-Anmeldung für Eltern ist bewusst noch nicht Bestandteil der aktuellen Umsetzung. Sie ist als mögliche Weiterentwicklung vorgemerkt; bis dahin bleibt der bestehende Magic-Link-Zugang für Eltern unverändert.</p>
    </section>
</main>
</body>
</html>
