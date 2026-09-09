<?php

declare(strict_types=1);

use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> $children */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elternportal · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Elternportal</div>
    <div class="topbar-actions">
        <a href="/parent/bookings">Meine Buchungen</a>
        <a href="/parent/booking">Schließfach buchen</a>
        <a href="/parent/support">Problem melden</a>
        <span><?= $e($parent->displayName()) ?></span>
        <form method="post" action="/parent/logout">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="link-button" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Ihre Kinder</h1>
        <p>Für verknüpfte Kinder können Sie regelkonforme freie Schließfächer auswählen, bestehende Buchungen verwalten und Probleme mit bereits zugeordneten Fächern direkt an die Schließfachverwaltung melden.</p>
    </header>

    <section class="card stack">
        <?php if ($children === []): ?>
            <p>Mit diesem Elternkontakt ist derzeit kein aktiver Schüler verknüpft.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($children as $child): ?>
                    <div class="entity-row compact-form">
                        <strong><?= $e((string) $child['first_name'] . ' ' . (string) $child['last_name']) ?></strong>
                        <div class="muted">Klasse <?= $e((string) $child['class_name']) ?> · Klassenstufe <?= (int) $child['grade'] ?></div>
                        <div class="compact-actions">
                            <a class="button button-secondary" href="/parent/bookings">Buchungen verwalten</a>
                            <a class="button button-secondary" href="/parent/booking?student_id=<?= (int) $child['id'] ?>">Schließfach auswählen</a>
                            <a class="button button-secondary" href="/parent/support">Problem melden</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
