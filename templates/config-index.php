<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var string $schoolName */
/** @var bool $baseUrlSecure */
/** @var bool $smtpConfigured */
/** @var bool $stripeConfigured */
/** @var string $stripeMode */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Konfiguration · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Administration</span>
        <h1>Konfiguration</h1>
        <p>Betrieblich relevante Einstellungen werden hier zentral gepflegt. Versions-, Pfad- und Datenbankstrukturwerte bleiben bewusst dateibasiert.</p>
    </header>

    <div class="grid">
        <section class="card stack">
            <div>
                <h2>Allgemein</h2>
                <p>Schulname und öffentliche kanonische HTTPS-Adresse der FachDock-Installation.</p>
            </div>
            <div class="settings-status-item">
                <strong><?= $e($schoolName !== '' ? $schoolName : 'Schulname fehlt') ?></strong>
                <span><?= $baseUrlSecure ? 'Öffentliche URL konfiguriert' : 'Öffentliche HTTPS-URL fehlt' ?></span>
            </div>
            <a class="button" href="/admin/config/general">Allgemein konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>Anmeldung & Sitzungen</h2>
                <p>Passwortregeln, Fehlversuche, Sperrzeiten, Staff-Sitzungen, Eltern-Sitzungen und Magic Links.</p>
            </div>
            <a class="button" href="/admin/config/auth">Anmeldung konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>IServ / OpenID Connect</h2>
                <p>Single Sign-on für Schüler und Lehrkräfte, automatische Schülerzuordnung, Lehrkräfterollen und OIDC-Verbindungstest.</p>
            </div>
            <a class="button" href="/admin/config/oidc">IServ konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>Buchungen</h2>
                <p>Empfehlungsanzahl, Reservierungsdauer, Zahlungs-Gnadenfrist und Zahlungsfrist nach BuT-Ablehnung.</p>
            </div>
            <a class="button" href="/admin/config/booking">Buchungen konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>E-Mail & SMTP</h2>
                <p>SMTP-Zugang, Absender, Worker-Batch, Versandlimit, Retry-Abstände und Processing-Timeout.</p>
            </div>
            <div class="settings-status-item">
                <strong>SMTP</strong>
                <span><?= $smtpConfigured ? 'konfiguriert' : 'nicht vollständig konfiguriert' ?></span>
            </div>
            <a class="button" href="/admin/config/mail">E-Mail konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>Stripe & Zahlung</h2>
                <p>Test-/Live-Modus, Währung, Checkout-Dauer, Secret Key und Webhook-Secret.</p>
            </div>
            <div class="settings-status-item">
                <strong><?= strtolower($stripeMode) === 'live' ? 'Live-Modus' : 'Testmodus' ?></strong>
                <span><?= $stripeConfigured ? 'zahlungsbereit konfiguriert' : 'Konfiguration unvollständig' ?></span>
            </div>
            <a class="button" href="/admin/config/stripe">Stripe konfigurieren</a>
        </section>

        <section class="card stack">
            <div>
                <h2>E-Mail-Betrieb</h2>
                <p>Vorlagen und aktuelle Versandwarteschlange werden getrennt von der technischen SMTP-Konfiguration verwaltet.</p>
            </div>
            <a class="button button-secondary" href="/admin/mail">Vorlagen & Warteschlange öffnen</a>
        </section>
    </div>

    <section class="card stack">
        <h2>Nicht über die Weboberfläche änderbar</h2>
        <p class="form-hint">Interne Werte wie <code>app.version</code>, <code>paths.*</code>, Datenbanktreiber sowie Installations- und Deploymentdetails bleiben absichtlich in den Konfigurationsdateien bzw. im Installer. Dadurch können betriebsgefährdende Änderungen nicht versehentlich im laufenden System vorgenommen werden.</p>
    </section>
</main>
</body>
</html>
