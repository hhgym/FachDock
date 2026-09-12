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
$chartValues = [
    'BuT-Prüfungen' => $dashboard['exemption_reviews'],
    'Zahlung fällig' => $dashboard['payment_due'],
    'Zahlungsprüfung' => $dashboard['payment_manual_review'],
    'In Verarbeitung' => $dashboard['payment_processing'],
    'Reservierungen' => $dashboard['active_reservations'],
];
$chartMax = max(1, ...array_values($chartValues));
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/dashboard.css?v=<?= $e($version) ?>">
    <script src="/assets/dashboard.js?v=<?= $e($version) ?>" defer></script>
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
    <div class="dashboard-intro">
        <header class="hero">
            <span class="eyebrow"><?= $e($staff->role->label()) ?></span>
            <h1>Willkommen, <?= $e($staff->displayName) ?></h1>
            <p>Die wichtigsten Arbeitsstände auf einen Blick.</p>
        </header>
        <span class="dashboard-version">FachDock <?= $e($version) ?></span>
    </div>

    <?php if ($dashboard['payment_manual_review'] > 0 || $dashboard['webhook_open'] > 0): ?>
        <div class="alert alert-error">
            <strong>Zahlungsprüfung erforderlich.</strong>
            <?php if ($dashboard['payment_manual_review'] > 0): ?><?= $dashboard['payment_manual_review'] ?> bezahlte Vorgänge benötigen eine manuelle Prüfung.<?php endif; ?>
            <?php if ($dashboard['webhook_open'] > 0): ?> <?= $dashboard['webhook_open'] ?> Stripe-Webhook-Ereignisse sind nicht vollständig verarbeitet.<?php endif; ?>
            <div><a href="/admin/payments">Zahlungsverwaltung öffnen</a></div>
        </div>
    <?php endif; ?>

    <section class="dashboard-grid" aria-label="Kennzahlen">
        <a class="metric-card" href="/admin/bookings?status=active">
            <span class="metric-card-top"><span class="metric-label">Aktive Buchungen</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['active_bookings'] ?>"><?= $dashboard['active_bookings'] ?></strong>
            <span class="metric-link">Buchungen anzeigen →</span>
        </a>
        <a class="metric-card <?= $dashboard['exemption_reviews'] > 0 ? 'metric-attention' : '' ?>" href="/admin/but">
            <span class="metric-card-top"><span class="metric-label">Offene BuT-Prüfungen</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['exemption_reviews'] ?>"><?= $dashboard['exemption_reviews'] ?></strong>
            <span class="metric-link">Prüfung öffnen →</span>
        </a>
        <a class="metric-card <?= $dashboard['payment_due'] > 0 ? 'metric-attention' : '' ?>" href="/admin/bookings?status=payment_due">
            <span class="metric-card-top"><span class="metric-label">Zahlung fällig</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['payment_due'] ?>"><?= $dashboard['payment_due'] ?></strong>
            <span class="metric-link">Buchungen anzeigen →</span>
        </a>
        <a class="metric-card <?= $dashboard['payment_manual_review'] > 0 ? 'metric-danger' : '' ?>" href="/admin/payments?status=manual_review">
            <span class="metric-card-top"><span class="metric-label">Manuelle Zahlungsprüfung</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['payment_manual_review'] ?>"><?= $dashboard['payment_manual_review'] ?></strong>
            <span class="metric-link">Zahlungen prüfen →</span>
        </a>
        <a class="metric-card" href="/admin/payments?status=processing_paid">
            <span class="metric-card-top"><span class="metric-label">Zahlungen in Verarbeitung</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['payment_processing'] ?>"><?= $dashboard['payment_processing'] ?></strong>
            <span class="metric-link">Zahlungen anzeigen →</span>
        </a>
        <div class="metric-card metric-static">
            <span class="metric-card-top"><span class="metric-label">Aktive Reservierungen</span><span class="metric-marker" aria-hidden="true"></span></span>
            <strong class="metric-value" data-dashboard-counter="<?= $dashboard['active_reservations'] ?>"><?= $dashboard['active_reservations'] ?></strong>
            <span class="metric-link">inkl. laufender Zahlungsvorgänge</span>
        </div>
    </section>

    <section class="dashboard-lower-grid">
        <article class="card stack dashboard-chart">
            <div>
                <span class="eyebrow">Arbeitsstände</span>
                <h2>Offene Vorgänge im Vergleich</h2>
                <p class="form-hint">Die Balken zeigen die Größenordnung der aktuellen Arbeitsstände.</p>
            </div>
            <div class="dashboard-chart-list">
                <?php foreach ($chartValues as $label => $value): ?>
                    <div class="dashboard-chart-row">
                        <span class="dashboard-chart-label"><?= $e($label) ?></span>
                        <progress class="dashboard-progress" max="<?= $chartMax ?>" value="<?= $value ?>" data-dashboard-progress="<?= $value ?>"><?= $value ?></progress>
                        <span class="dashboard-chart-value"><?= $value ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card stack dashboard-actions">
            <div>
                <span class="eyebrow">Direktzugriff</span>
                <h2>Häufige Aufgaben</h2>
            </div>
            <div class="dashboard-actions-list">
                <a class="dashboard-action-link" href="/admin/operations">Schließfachbetrieb</a>
                <a class="dashboard-action-link" href="/admin/bookings?status=active">Buchungen</a>
                <a class="dashboard-action-link" href="/admin/but">BuT-Prüfung</a>
                <?php if ($staff->isAdministrator()): ?>
                    <a class="dashboard-action-link" href="/admin/system/status">Systemstatus</a>
                <?php endif; ?>
            </div>
            <div class="dashboard-status-row">
                <span>Online-Zahlung</span>
                <?php if ($stripeCheckoutAvailable): ?>
                    <span class="badge"><?= $stripeMode === 'test' ? 'Testmodus' : 'Livebetrieb' ?></span>
                <?php else: ?>
                    <span class="badge">Nicht bereit</span>
                <?php endif; ?>
            </div>
            <?php if (!$stripeCheckoutAvailable && $staff->isAdministrator()): ?>
                <p class="form-hint">Die Online-Zahlung ist noch nicht vollständig eingerichtet.</p>
                <a class="button button-secondary" href="/admin/config/stripe">Zahlung konfigurieren</a>
            <?php endif; ?>
        </article>
    </section>
</main>
</body>
</html>
