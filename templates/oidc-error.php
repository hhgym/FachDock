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
    <title>IServ-Anmeldung · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
    <header class="hero"><span class="eyebrow">IServ</span><h1>Anmeldung nicht möglich</h1></header>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
    <a class="button button-secondary" href="/login">Zur Anmeldung</a>
</main>
</body>
</html>
