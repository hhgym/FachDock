<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var array<string, mixed> $form */
/** @var list<array<string, mixed>> $contacts */
/** @var list<array<string, mixed>> $links */
/** @var list<array<string, mixed>> $students */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$formValue = static function (array $form, string $key): string {
    $value = $form[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elternkontakte · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternkontakte</div>
    <div class="topbar-actions"><a href="/admin/mail">E-Mail</a><a href="/admin/students">Schüler</a><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Identitäten</span>
        <h1>Elternkontakte verwalten</h1>
        <p>Elternkontakte und aktive Eltern-Kind-Verknüpfungen werden getrennt von Schüler-, Zahlungs- und Buchungsdaten geführt.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Elternkontakt mit Schüler verknüpfen</h2>
        <p class="form-hint">Die Verknüpfung durch die Administration bestätigt nicht die Inhaberschaft der E-Mail-Adresse. Neue Kontakte bleiben bis zur Bestätigung über einen Magic Link im Status „ausstehend“.</p>
        <form method="post" action="/admin/parents/link" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>E-Mail-Adresse
                <input type="email" name="email" value="<?= $e($formValue($form, 'email')) ?>" maxlength="255" required autocomplete="off">
            </label>
            <label>Schüler
                <select name="student_id" required>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= $formValue($form, 'student_id') === (string) $student['id'] ? 'selected' : '' ?>>
                            <?= $e((string) $student['class_name']) ?> · <?= $e((string) $student['last_name']) ?>, <?= $e((string) $student['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Vorname des Elternteils <small>optional</small>
                <input type="text" name="first_name" value="<?= $e($formValue($form, 'first_name')) ?>" maxlength="255" autocomplete="off">
            </label>
            <label>Nachname des Elternteils <small>optional</small>
                <input type="text" name="last_name" value="<?= $e($formValue($form, 'last_name')) ?>" maxlength="255" autocomplete="off">
            </label>
            <button class="button" type="submit">Verknüpfung anlegen</button>
        </form>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div><h2>Aktive Verknüpfungen</h2><p class="form-hint">Beim Entfernen bleibt der historische Datensatz erhalten; der Portalzugriff über diese Verknüpfung endet sofort.</p></div>
            <span class="badge"><?= count($links) ?> aktiv</span>
        </div>
        <?php if ($links === []): ?>
            <p>Noch keine Eltern-Kind-Verknüpfungen vorhanden.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($links as $link): ?>
                    <details class="entity-row">
                        <summary>
                            <?= $e((string) $link['email']) ?> → <?= $e((string) $link['class_name']) ?> · <?= $e((string) $link['last_name']) ?>, <?= $e((string) $link['first_name']) ?>
                        </summary>
                        <form method="post" action="/admin/parents/unlink" class="compact-form stack">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="link_id" value="<?= (int) $link['link_id'] ?>">
                            <p class="form-hint">Verknüpft seit <?= $e((string) $link['started_at']) ?>. Matrikelnummern werden in dieser Ansicht bewusst nicht angezeigt.</p>
                            <label>Begründung für das Entfernen
                                <input type="text" name="reason" maxlength="255" required placeholder="z. B. Zuordnung korrigiert">
                            </label>
                            <button class="button button-secondary" type="submit">Verknüpfung entfernen</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Elternkontakte</h2>
                <p class="form-hint">Die reguläre Verifikation erfolgt ausschließlich über einen einmaligen Link an die gespeicherte E-Mail-Adresse. Administratoren können das Elternportal zusätzlich als aktiven Kontakt testen; dabei wird keine E-Mail versendet und die Testansicht wird sichtbar gekennzeichnet und protokolliert.</p>
            </div>
            <span class="badge"><?= count($contacts) ?> Kontakte</span>
        </div>
        <?php if ($contacts === []): ?>
            <p>Noch keine Elternkontakte vorhanden.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>E-Mail</th><th>Name</th><th>Status</th><th>Verifiziert</th><th>Aktive Kinder</th><th>Aktion</th></tr></thead>
                    <tbody>
                    <?php foreach ($contacts as $contact): ?>
                        <tr>
                            <td><?= $e((string) $contact['email']) ?></td>
                            <td><?= $e(trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? ''))) ?: '–') ?></td>
                            <td><?= !$contact['active'] ? 'deaktiviert' : ((string) $contact['status'] === 'verified' ? 'verifiziert' : 'ausstehend') ?></td>
                            <td><?= $contact['verified_at'] !== null ? $e((string) $contact['verified_at']) : '–' ?></td>
                            <td><?= (int) $contact['active_link_count'] ?></td>
                            <td>
                                <?php if ($contact['active']): ?>
                                    <div class="stack">
                                        <form method="post" action="/admin/parents/preview">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="parent_contact_id" value="<?= (int) $contact['id'] ?>">
                                            <button class="button button-secondary" type="submit">Als Elternteil testen</button>
                                        </form>
                                        <?php if ($contact['verified_at'] === null): ?>
                                            <form method="post" action="/admin/parents/send-verification">
                                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                <input type="hidden" name="parent_contact_id" value="<?= (int) $contact['id'] ?>">
                                                <button class="button button-secondary" type="submit">Bestätigungslink senden</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>–<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Magic Links</h2>
        <p>Magic Links sind zufällig, standardmäßig 15 Minuten gültig, nur einmal verwendbar und werden ausschließlich gehasht gespeichert. Der Klartext-Link wird nur für den Versand in der E-Mail-Queue benötigt und nach erfolgreichem Versand aus dem Queue-Eintrag entfernt.</p>
    </section>
</main>
</body>
</html>
