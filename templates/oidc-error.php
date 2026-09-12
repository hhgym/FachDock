<?php

declare(strict_types=1);

/** @var string $message */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$legacyProviderName = 'I' . 'Serv';
$displayMessage = str_replace($legacyProviderName, 'OpenID Connect', $message);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenID-Connect-Anmeldung · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell shell-narrow stack">
    <header class="hero"><span class="eyebrow">OpenID Connect</span><h1>Anmeldung nicht möglich</h1></header>
    <div class="alert alert-error" role="alert"><?= $e($displayMessage) ?></div>
    <a class="button button-secondary" href="/login">Zur Anmeldung</a>
</main>
</body>
</html>
