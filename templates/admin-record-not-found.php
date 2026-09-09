<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $title */
/** @var string $message */
/** @var string $backUrl */
/** @var string $backLabel */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong></header>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Verwaltung</span>
        <h1><?= $e($title) ?></h1>
    </header>
    <section class="card stack">
        <p><?= $e($message) ?></p>
        <p><a href="<?= $e($backUrl) ?>"><?= $e($backLabel) ?></a></p>
    </section>
</main>
</body>
</html>
