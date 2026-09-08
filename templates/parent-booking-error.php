<?php

declare(strict_types=1);

/** @var string $message */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchung nicht möglich · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Buchung nicht möglich</h1>
    </header>
    <section class="card stack">
        <p><?= $e($message) ?></p>
        <p><a class="button" href="/parent/booking">Zur Schließfachauswahl</a></p>
        <p><a href="/parent">Zur Elternübersicht</a></p>
    </section>
</main>
</body>
</html>
