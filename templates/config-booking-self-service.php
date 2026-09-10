<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var int $changeLimit */
/** @var list<string> $errors */
/** @var bool $success */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungs-Self-Service · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell shell-narrow stack">
    <header class="hero">
        <span class="eyebrow">Konfiguration · Buchungen</span>
        <h1>Self-Service und Schließfachwechsel</h1>
        <p>Begrenzt ausschließlich freiwillige Wechsel, die Eltern selbst im Elternportal ausführen.</p>
    </header>
    <p><a href="/admin/config/booking">← Zu den Buchungseinstellungen</a></p>

    <?php if ($success): ?><div class="alert alert-success">Die Self-Service-Einstellung wurde gespeichert.</div><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form class="card stack" method="post" action="/admin/config/booking/self-service">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label>Maximale selbstständige Wechsel pro Schuljahr
            <input type="number" name="self_service_change_limit" min="0" max="20" value="<?= $changeLimit ?>" required>
            <small>Standard: 2. Der Wert 0 deaktiviert freiwillige Wechsel im Elternportal.</small>
        </label>
        <div class="alert alert-neutral">
            Administrative Wechsel durch die Schließfachverwaltung zählen nicht gegen dieses Limit. Auch Änderungen aus der Administrator-Testansicht verbrauchen keinen Eltern-Wechsel.
        </div>
        <button class="button" type="submit">Self-Service-Einstellung speichern</button>
    </form>
</main>
</body>
</html>
