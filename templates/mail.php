<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $templates */
/** @var list<array<string, mixed>> $queue */
/** @var bool $smtpConfigured */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$statusLabels = [
    'waiting' => 'wartet',
    'processing' => 'wird versendet',
    'sent' => 'versendet',
    'failed' => 'fehlgeschlagen',
    'canceled' => 'abgebrochen',
];
$sendNow = isset($_GET['send_now']) && is_scalar($_GET['send_now']) ? (string) $_GET['send_now'] : '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · E-Mail</div>
    <div class="topbar-actions"><a href="/admin/parents">Elternkontakte</a><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Kommunikation</span>
        <h1>E-Mail-Zentrale</h1>
        <p>Versionierte Vorlagen und die persistente Versandwarteschlange verwalten.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($sendNow === 'sent'): ?>
        <div class="alert alert-success"><strong>Sofortversand erfolgreich.</strong> Die ausgewählte Nachricht wurde direkt an den SMTP-Server übergeben.</div>
    <?php elseif ($sendNow === 'deferred'): ?>
        <div class="alert alert-neutral"><strong>Sofortversand nicht abgeschlossen.</strong> Die Nachricht bleibt für einen erneuten Versuch in der Warteschlange. Prüfen Sie Status und Fehlertext.</div>
    <?php elseif ($sendNow === 'failed'): ?>
        <div class="alert alert-error"><strong>Sofortversand fehlgeschlagen.</strong> Bitte SMTP-Konfiguration und Warteschlangenstatus prüfen.</div>
    <?php endif; ?>

    <?php if ($smtpConfigured): ?>
        <div class="alert alert-success">SMTP ist grundsätzlich konfiguriert. Kennwörter und andere Geheimnisse werden hier nicht angezeigt.</div>
    <?php else: ?>
        <div class="alert alert-neutral"><strong>SMTP noch nicht vollständig konfiguriert.</strong> Nachrichten können bereits in die Warteschlange gestellt werden, der Worker kann sie aber erst nach Einrichtung von Host und Absenderadresse versenden.</div>
    <?php endif; ?>

    <section class="card stack">
        <div>
            <h2>E-Mail-Vorlagen</h2>
            <p class="form-hint">Beim Speichern wird immer eine neue, unveränderliche Version angelegt. Bestehende Versandhistorie verweist weiterhin auf die damals verwendete Version.</p>
        </div>

        <?php foreach ($templates as $template): ?>
            <?php
            $allowed = json_decode((string) $template['allowed_placeholders'], true);
            $allowedLabels = [];
            if (is_array($allowed)) {
                foreach ($allowed as $placeholder) {
                    if (is_string($placeholder)) {
                        $allowedLabels[] = '{{' . $placeholder . '}}';
                    }
                }
            }
            ?>
            <details class="entity-row">
                <summary>
                    <code><?= $e((string) $template['template_key']) ?></code>
                    <span class="status-pill">v<?= (int) $template['version'] ?></span>
                    <span class="status-pill"><?= (int) $template['active'] === 1 ? 'aktiv' : 'inaktiv' ?></span>
                </summary>
                <form method="post" action="/admin/mail/template" class="stack compact-form">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="template_key" value="<?= $e((string) $template['template_key']) ?>">
                    <p class="form-hint"><strong>Erlaubte Platzhalter:</strong> <?= $allowedLabels === [] ? 'keine' : $e(implode(', ', $allowedLabels)) ?></p>
                    <label>Betreff
                        <input name="subject_template" required maxlength="500" value="<?= $e((string) $template['subject_template']) ?>">
                    </label>
                    <label>HTML-Fassung
                        <textarea name="html_template" rows="10" required><?= $e((string) $template['html_template']) ?></textarea>
                        <small>HTML wird als Quelltext bearbeitet und hier bewusst nicht ausgeführt.</small>
                    </label>
                    <label>Textfassung
                        <textarea name="text_template" rows="9" required><?= $e((string) $template['text_template']) ?></textarea>
                    </label>
                    <label class="check-label"><input type="checkbox" name="active" value="1" <?= (int) $template['active'] === 1 ? 'checked' : '' ?>> Neue Version sofort aktivieren</label>
                    <button class="button" type="submit">Neue Version speichern</button>
                </form>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="card stack">
        <div>
            <h2>Versandwarteschlange</h2>
            <p class="form-hint">Der Cronjob ruft <code>bin/fachdock mail:work</code> auf. Magic Links werden mit Sofortpriorität eingereiht und direkt im anfordernden Request versendet. Die Queue bleibt dabei als sichere Persistenz und für Wiederholungsversuche erhalten.</p>
        </div>

        <?php if ($queue === []): ?>
            <p>Noch keine E-Mail in der Warteschlange.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>ID</th><th>Empfänger</th><th>Vorlage</th><th>Status</th><th>Versuche</th><th>Verfügbar</th><th>Fehler</th><th>Aktion</th></tr></thead>
                    <tbody>
                    <?php foreach ($queue as $mail): ?>
                        <?php $status = (string) $mail['status']; ?>
                        <tr>
                            <td><?= (int) $mail['id'] ?></td>
                            <td><?= $e((string) $mail['recipient_email']) ?><br><small><?= $e((string) ($mail['subject'] ?? '')) ?></small></td>
                            <td><code><?= $e((string) $mail['template_key']) ?></code><br><small>v<?= (int) $mail['template_version'] ?></small></td>
                            <td><?= $e($statusLabels[$status] ?? $status) ?></td>
                            <td><?= (int) $mail['attempts'] ?></td>
                            <td><?= $e((string) $mail['available_at']) ?></td>
                            <td><?= $mail['last_error'] === null ? '–' : $e((string) $mail['last_error']) ?></td>
                            <td>
                                <div class="row-actions">
                                    <?php if (in_array($status, ['waiting', 'failed'], true)): ?>
                                        <form method="post" action="/admin/mail/send-now">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="queue_id" value="<?= (int) $mail['id'] ?>">
                                            <button class="button" type="submit">Jetzt senden</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($status === 'failed'): ?>
                                        <form method="post" action="/admin/mail/retry">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="queue_id" value="<?= (int) $mail['id'] ?>">
                                            <button class="button button-secondary" type="submit">Erneut einreihen</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($status, ['waiting', 'failed'], true)): ?>
                                        <form method="post" action="/admin/mail/cancel">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="queue_id" value="<?= (int) $mail['id'] ?>">
                                            <button class="link-button danger-link" type="submit">Abbrechen</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="form-hint">„Jetzt senden“ umgeht den Cron-Zeitpunkt, nicht jedoch das globale Versandlimit. Bei SMTP-Fehlern bleibt die Nachricht mit Fehlertext und neuem Retry-Zeitpunkt erhalten.</p>
    </section>
</main>
</body>
</html>
