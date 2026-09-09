<?php

declare(strict_types=1);
use FachDock\Auth\AuthenticatedStaff;
/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var string $schoolName */
/** @var string $baseUrl */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Allgemein · FachDock</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="topbar"><div><strong>FachDock</strong> · Konfiguration</div></header>
<main class="shell stack"><header class="hero"><span class="eyebrow">Konfiguration</span><h1>Allgemein</h1><p>Grunddaten der Installation und öffentliche Adresse.</p></header>
<p><a href="/admin/config">← Zur Konfigurationsübersicht</a></p>
<?php if ($success): ?><div class="alert alert-success">Die allgemeinen Einstellungen wurden gespeichert.</div><?php endif; ?>
<?php if ($errors !== []): ?><div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="card stack" method="post" action="/admin/config/general"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><div class="grid">
<label>Schulname<input name="school_name" maxlength="160" value="<?= $e($schoolName) ?>" required><small>Wird in Benachrichtigungen und der Oberfläche verwendet.</small></label>
<label class="wide">Öffentliche Basis-URL<input type="url" name="base_url" value="<?= $e($baseUrl) ?>" placeholder="https://fachdock.example.de" required><small>Kanonische HTTPS-Adresse ohne abschließenden Schrägstrich; wird für Magic Links und Stripe-Webhooks verwendet.</small></label>
</div><button class="button" type="submit">Allgemeine Einstellungen speichern</button></form></main></body></html>
