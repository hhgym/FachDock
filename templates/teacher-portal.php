<?php

declare(strict_types=1);

use FachDock\Identity\AuthenticatedOidcIdentity;

/** @var AuthenticatedOidcIdentity $identity */
/** @var string $csrfToken */
/** @var string $query */
/** @var list<array<string, mixed>> $assignments */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lehrkräfte · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Lehrkräfte</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">OpenID Connect · nur lesend</span>
        <h1>Schließfachübersicht</h1>
        <p>Angemeldet als <?= $e($identity->label()) ?>. Dieser Bereich bietet ausschließlich lesenden Zugriff.</p>
    </header>

    <form class="card cluster" method="get" action="/teacher">
        <label>Schüler, Klasse oder Fach suchen
            <input name="q" value="<?= $e($query) ?>" placeholder="z. B. 7-1, Müller oder A-01-2">
        </label>
        <button class="button" type="submit">Suchen</button>
    </form>

    <section class="card stack">
        <h2>Aktive Schüler</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Schüler</th><th>Klasse</th><th>Schuljahr</th><th>Schließfach</th><th>Standort</th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $row): ?>
                <tr>
                    <td><?= $e((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?><br><small><?= $e((string) $row['matrikelnummer']) ?></small></td>
                    <td><?= $e((string) $row['class_name']) ?></td>
                    <td><?= $e((string) ($row['school_year'] ?? '—')) ?></td>
                    <td><?= $e((string) ($row['locker_name'] ?? '—')) ?></td>
                    <td><?= $e(trim(implode(' · ', array_filter([
                        (string) ($row['building_name'] ?? ''),
                        (string) ($row['floor_name'] ?? ''),
                        (string) ($row['area_name'] ?? ''),
                    ]))) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($assignments === []): ?><tr><td colspan="5">Keine passenden Datensätze gefunden.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="form-hint">Es werden höchstens 100 Treffer angezeigt. Änderungen an Buchungen oder Stammdaten sind in diesem Bereich technisch nicht möglich.</p>
    </section>

    <form method="post" action="/sso/logout">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <button class="button button-secondary" type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
