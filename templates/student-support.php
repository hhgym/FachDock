<?php

declare(strict_types=1);

use FachDock\Operations\LockerIncidentCategory;
use FachDock\Operations\LockerIncidentStatus;

/** @var string $csrfToken */
/** @var array<string, mixed> $student */
/** @var list<string> $errors */
/** @var bool $success */
/** @var list<array<string, mixed>> $assignments */
/** @var list<array<string, mixed>> $incidents */
/** @var list<LockerIncidentCategory> $categories */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schüler-Schließfachservice · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Schüler-Schließfachservice</div>
    <form method="post" action="/student/support/logout">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <button class="link-button" type="submit">Abmelden</button>
    </form>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Schüler-Schließfachservice</span>
        <h1>Hallo <?= $e((string) $student['first_name']) ?></h1>
        <p>Hier kannst du Probleme mit deinem aktuell zugeordneten Schließfach melden und sehen, wie weit die Bearbeitung ist.</p>
    </header>

    <?php if ($success): ?><div class="alert alert-success">Deine Meldung wurde aufgenommen.</div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card stack">
        <h2>Problem melden</h2>
        <?php if ($assignments === []): ?>
            <p>Dir ist derzeit kein aktives Schließfach zugeordnet.</p>
        <?php else: ?>
            <form class="stack" method="post" action="/student/support/report">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <div class="grid">
                    <label>Schließfach
                        <select name="booking_id" required>
                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int) $assignment['booking_id'] ?>"><?= $e((string) $assignment['locker_name']) ?> · <?= $e((string) $assignment['school_year_label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Art des Problems
                        <select name="category" required>
                            <?php foreach ($categories as $category): ?><option value="<?= $e($category->value) ?>"><?= $e($category->label()) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="wide">Beschreibung
                        <textarea name="description" rows="4" maxlength="4000" required placeholder="Beschreibe kurz, was passiert ist."></textarea>
                    </label>
                </div>
                <button class="button" type="submit">Meldung absenden</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Meine Meldungen</h2>
        <?php if ($incidents === []): ?>
            <p>Noch keine Meldungen vorhanden.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($incidents as $incident): $category = LockerIncidentCategory::tryFrom((string) $incident['category']); $status = LockerIncidentStatus::tryFrom((string) $incident['status']); ?>
                    <div class="entity-row">
                        <div>
                            <strong>#<?= (int) $incident['id'] ?> · <?= $e($category?->label() ?? (string) $incident['category']) ?></strong>
                            <div class="muted">Schließfach <?= $e((string) $incident['locker_name']) ?> · <?= $e((string) $incident['opened_at']) ?></div>
                        </div>
                        <div>
                            <span class="badge"><?= $e($status?->label() ?? (string) $incident['status']) ?></span>
                            <?php if (trim((string) ($incident['resolution_note'] ?? '')) !== ''): ?><p><small><?= nl2br($e((string) $incident['resolution_note'])) ?></small></p><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
