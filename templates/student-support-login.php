<?php

declare(strict_types=1);

/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schülerzugang · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="shell stack auth-shell">
    <header class="hero">
        <span class="eyebrow">Schülerzugang</span>
        <h1>Problem mit deinem Schließfach?</h1>
        <p>Melde dich mit deiner Matrikelnummer und dem FachDock-Zugangscode an. Der Zugang ist als Fallback vorgesehen, bis die IServ-Anmeldung zur Verfügung steht.</p>
    </header>

    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form class="card stack" method="post" action="/student/support/login" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label>Matrikelnummer
            <input name="matrikelnummer" autocomplete="username" required>
        </label>
        <label>FachDock-Zugangscode
            <input name="access_code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX" required>
        </label>
        <button class="button" type="submit">Anmelden</button>
    </form>

    <p class="muted">Den Zugangscode erhältst du von der Schule. Er wird nicht per E-Mail benötigt.</p>
</main>
</body>
</html>
