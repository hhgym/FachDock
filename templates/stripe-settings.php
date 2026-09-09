<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var string $mode */
/** @var string $currency */
/** @var int $checkoutMinutes */
/** @var bool $secretConfigured */
/** @var bool $webhookConfigured */
/** @var string $baseUrl */
/** @var string $webhookUrl */
/** @var bool $baseUrlSecure */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stripe · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Stripe</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Konfiguration</span>
        <h1>Stripe und Zahlungen</h1>
        <p>Hier werden die öffentliche FachDock-Adresse, Zahlungsmodus, Checkout-Dauer und die lokalen Stripe-Zugangsdaten verwaltet.</p>
    </header>

    <?php if ($success): ?><div class="alert alert-success">Die Stripe-Konfiguration wurde gespeichert.</div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Konfigurationsstatus</h2>
                <p class="form-hint">Geheime Schlüssel werden niemals im Klartext angezeigt.</p>
            </div>
            <span class="badge"><?= $mode === 'live' ? 'Live-Modus' : 'Testmodus' ?></span>
        </div>
        <div class="settings-status-grid">
            <div class="settings-status-item">
                <strong>Secret Key</strong>
                <span><?= $secretConfigured ? 'konfiguriert' : 'fehlt' ?></span>
            </div>
            <div class="settings-status-item">
                <strong>Webhook-Secret</strong>
                <span><?= $webhookConfigured ? 'konfiguriert' : 'fehlt' ?></span>
            </div>
            <div class="settings-status-item wide">
                <strong>Webhook-Endpunkt</strong>
                <span class="code-value"><?= $webhookUrl !== '' ? $e($webhookUrl) : 'Basis-URL noch nicht konfiguriert' ?></span>
            </div>
        </div>
        <?php if (!$baseUrlSecure): ?>
            <div class="alert alert-neutral">
                Für Stripe Checkout ist eine kanonische HTTPS-Adresse erforderlich. Trage sie unten unter <strong>Öffentliche Basis-URL</strong> ein.
            </div>
        <?php endif; ?>
    </section>

    <form class="card stack" method="post" action="/admin/config/stripe" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <h2>Zahlungseinstellungen</h2>
        <div class="grid">
            <label class="wide">Öffentliche Basis-URL
                <input type="url" name="base_url" value="<?= $e($baseUrl) ?>" placeholder="https://fachdock.example.de" inputmode="url" autocapitalize="none" spellcheck="false" required>
                <small>Die öffentliche HTTPS-Adresse dieser FachDock-Installation ohne abschließenden Schrägstrich. Daraus wird automatisch der Stripe-Webhook <code>/webhooks/stripe</code> gebildet.</small>
            </label>
            <label>Stripe-Modus
                <select name="mode" required>
                    <option value="test" <?= $mode === 'test' ? 'selected' : '' ?>>Testmodus</option>
                    <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>>Live-Modus</option>
                </select>
                <small>Testmodus erwartet <code>sk_test_…</code>, Live-Modus <code>sk_live_…</code>.</small>
            </label>
            <label>Währung
                <input name="currency" value="<?= $e($currency) ?>" maxlength="3" pattern="[A-Za-z]{3}" required>
                <small>ISO-4217-Code, standardmäßig EUR.</small>
            </label>
            <label>Checkout-Gültigkeit in Minuten
                <input type="number" name="checkout_minutes" min="30" max="1440" value="<?= $checkoutMinutes ?>" required>
                <small>Zwischen 30 Minuten und 24 Stunden.</small>
            </label>
        </div>

        <h2>Zugangsdaten</h2>
        <p class="form-hint">Leere Felder behalten den bereits gespeicherten Wert bei. Neue Geheimnisse werden ausschließlich in <code>config/secrets.local.php</code> gespeichert.</p>
        <div class="grid">
            <label>Secret Key
                <input type="password" name="secret_key" spellcheck="false" placeholder="<?= $secretConfigured ? 'gespeicherten Schlüssel beibehalten' : 'sk_test_…' ?>">
            </label>
            <label>Webhook-Secret
                <input type="password" name="webhook_secret" spellcheck="false" placeholder="<?= $webhookConfigured ? 'gespeichertes Secret beibehalten' : 'whsec_…' ?>">
            </label>
        </div>

        <div class="alert alert-neutral">
            Der Webhook in Stripe muss mindestens die Ereignisse <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code> und <code>checkout.session.expired</code> senden.
        </div>
        <button class="button" type="submit">Stripe-Konfiguration speichern</button>
    </form>
</main>
</body>
</html>
