<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var string $appName */
/** @var string $version */
/** @var string $schoolName */
/** @var AuthenticatedStaff $staff */
/** @var array{active_bookings: int, exemption_reviews: int, payment_due: int, payment_manual_review: int, payment_processing: int, active_reservations: int, webhook_open: int} $dashboard */
/** @var string $stripeMode */
/** @var bool $stripeCheckoutAvailable */
/** @var list<string> $stripeProblems */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/dashboard.css?v=<?= $e($version) ?>">
</head>
<body>
<header class="topbar">
    <div><strong><?= $e($appName) ?></strong><?php if ($schoolName !== ''): ?> · <?= $e($schoolName) ?><?php endif; ?></div>
    <div class="topbar-actions">
        <a href="/admin/locations">Standorte</a>
        <a href="/admin/operations">Schließfachbetrieb</a>
        <a href="/admin/but">BuT-Prüfung</a>
        <?php if ($staff->isAdministrator()): ?>
            <a href="/admin/students">Schüler</a>
            <a href="/admin/parents">Eltern</a>
            <a href="/admin/school-years">Schuljahre</a>
            <a href="/admin/allocation-rules">Zuteilungsregeln</a>
            <a href="/admin/recommendations">Empfehlungen</a>
            <a href="/admin/booking-selection">Buchungsauswahl</a>
            <a href="/admin/mail">E-Mail</a>
            <a href="/admin/system/status">Systemstatus</a>
            <a href="/admin/system/update">Updates</a>
        <?php endif; ?>
        <a href="/account/password">Passwort</a>
        <a href="/account/sessions">Sitzungen</a>
        <form method="post" action="/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow"><?= $e($staff->role->label()) ?></span>
        <h1>Willkommen, <?= $e($staff->displayName) ?></h1>
        <p>Version <?= $e($version) ?></p>
    </header>

    <?php if ($dashboard['payment_manual_review'] > 0 || $dashboard['webhook_open'] > 0): ?>
        <div class="alert alert-error">
            <strong>Zahlungsprüfung erforderlich.</strong>
            <?php if ($dashboard['payment_manual_review'] > 0): ?><?= $dashboard['payment_manual_review'] ?> bezahlte Vorgänge benötigen eine manuelle Prüfung.<?php endif; ?>
            <?php if ($dashboard['webhook_open'] > 0): ?> <?= $dashboard['webhook_open'] ?> Stripe-Webhook-Ereignisse sind nicht vollständig verarbeitet.<?php endif; ?>
            <div><a href="/admin/payments">Zahlungsverwaltung öffnen</a></div>
        </div>
    <?php endif; ?>

    <section class="dashboard-grid" aria-label="Arbeitsübersicht">
        <a class="metric-card" href="/admin/bookings?status=active">
            <span class="metric-label">Aktive Buchungen</span>
            <strong class="metric-value"><?= $dashboard['active_bookings'] ?></strong>
            <span class="metric-link">Buchungen anzeigen →</span>
        </a>
        <a class="metric-card <?= $dashboard['exemption_reviews'] > 0 ? 'metric-attention' : '' ?>" href="/admin/but">
            <span class="metric-label">Offene BuT-Prüfungen</span>
            <strong class="metric-value"><?= $dashboard['exemption_reviews'] ?></strong>
            <span class="metric-link">Prüfung öffnen →</span>
        </a>
        <a class="metric-card <?= $dashboard['payment_due'] > 0 ? 'metric-attention' : '' ?>" href="/admin/bookings?status=payment_due">
            <span class="metric-label">Zahlung fällig</span>
            <strong class="metric-value"><?= $dashboard['payment_due'] ?></strong>
            <span class="metric-link">Buchungen anzeigen →</span>
        </a>
        <a class="metric-card <?= $dashboard['payment_manual_review'] > 0 ? 'metric-danger' : '' ?>" href="/admin/payments?status=manual_review">
            <span class="metric-label">Manuelle Zahlungsprüfung</span>
            <strong class="metric-value"><?= $dashboard['payment_manual_review'] ?></strong>
            <span class="metric-link">Zahlungen prüfen →</span>
        </a>
        <a class="metric-card" href="/admin/payments?status=processing_paid">
            <span class="metric-label">Zahlungen in Verarbeitung</span>
            <strong class="metric-value"><?= $dashboard['payment_processing'] ?></strong>
            <span class="metric-link">Zahlungen anzeigen →</span>
        </a>
        <div class="metric-card metric-static">
            <span class="metric-label">Aktive Reservierungen</span>
            <strong class="metric-value"><?= $dashboard['active_reservations'] ?></strong>
            <span class="metric-link">inkl. laufender Zahlungsvorgänge</span>
        </div>
    </section>

    <section class="card stack">
        <span class="eyebrow">Betrieb</span>
        <h2>Schließfachservice</h2>
        <p>Defekte, Notöffnungen, Sperren und Wartung werden in einer eigenen Vorgangshistorie geführt.</p>
        <div class="compact-actions">
            <a class="button" href="/admin/operations">Schließfachbetrieb öffnen</a>
            <?php if ($staff->isAdministrator()): ?><a class="button button-secondary" href="/admin/system/status">Systemstatus prüfen</a><?php endif; ?>
        </div>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <span class="eyebrow">Online-Zahlung</span>
                <h2>Stripe-Status</h2>
            </div>
            <?php if ($stripeCheckoutAvailable): ?>
                <span class="badge"><?= $stripeMode === 'test' ? 'Testmodus' : 'Livebetrieb' ?></span>
            <?php else: ?>
                <span class="badge">Nicht bereit</span>
            <?php endif; ?>
        </div>

        <?php if ($stripeCheckoutAvailable): ?>
            <?php if ($stripeMode === 'test'): ?>
                <div class="alert alert-neutral"><strong>Stripe-Testmodus aktiv.</strong> Der komplette Zahlungsablauf kann getestet werden, ohne echte Zahlungen zu verarbeiten.</div>
            <?php else: ?>
                <div class="alert alert-success"><strong>Stripe ist für Live-Zahlungen eingerichtet.</strong> Secret Key, Webhook-Secret und HTTPS-Basis-URL sind konfiguriert.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-error">
                <strong>Online-Zahlung ist nicht vollständig eingerichtet.</strong>
                <ul><?php foreach ($stripeProblems as $problem): ?><li><?= $e($problem) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($staff->isAdministrator()): ?>
            <p><a class="button button-secondary" href="/admin/config/stripe">Stripe & Zahlung konfigurieren</a></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>FachDock-Grundsystem</h2>
        <p>Standort- und Personenverwaltung, Schuljahre und Zuteilungsregeln, Eltern- und Schüler-Selbstservice, Reservierungen, Buchungen, Schließfachbetrieb, BuT-Prüfung, Stripe-Zahlungen, E-Mail-Infrastruktur und der GitHub-Updatekanal sind miteinander verbunden.</p>
    </section>
</main>
</body>
</html>
