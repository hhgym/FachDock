<?php

declare(strict_types=1);

/** @var string $appName */
/** @var string $version */
/** @var string $schoolName */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell">
    <header class="hero">
        <span class="eyebrow"><?= $e($appName) ?></span>
        <h1>Grundinstallation abgeschlossen</h1>
        <p><?= $schoolName !== '' ? $e($schoolName) . ' · ' : '' ?>Version <?= $e($version) ?></p>
    </header>
    <section class="card">
        <h2>FachDock ist bereit für die nächsten Module.</h2>
        <p>Das Core-Fundament mit Konfiguration, Migrationen, Logging und Web-Installer ist aktiv.</p>
    </section>
</main>
</body>
</html>
