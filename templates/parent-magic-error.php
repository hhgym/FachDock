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
    <title>Magic Link · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Link nicht verwendbar</h1>
    </header>
    <div class="alert alert-error"><?= $e($message) ?></div>
    <section class="card"><a href="/parent/login">Neuen Anmeldelink anfordern</a></section>
</main>
</body>
</html>
