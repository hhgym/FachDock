<?php

declare(strict_types=1);

use FachDock\Operations\LockerIncidentCategory;
use FachDock\Operations\LockerIncidentStatus;
use FachDock\Parent\AuthenticatedParent;

/** @var AuthenticatedParent $parent */
/** @var string $csrfToken */
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
    <title>Schließfachproblem melden · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Elternportal</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Elternportal</span>
        <h1>Schließfachproblem melden</h1>
        <p>Defekte, Schloss- oder Türprobleme sowie notwendige Notöffnungen können direkt an die Schließfachverwaltung gemeldet werden.</p>
    </header>

    <?php if ($success): ?><div class="alert alert-success">Die Meldung wurde aufgenommen. Der Bearbeitungsstatus ist unten sichtbar.</div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card stack">
        <h2>Neue Meldung</h2>
        <?php if ($assignments === []): ?>
            <p>Für die aktuell verknüpften Kinder gibt es derzeit kein aktiv zugeordnetes Schließfach.</p>
        <?php else: ?>
            <form class="stack" method="post" action="/parent/support/report">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <div class="grid">
                    <label>Schließfach
                        <select name="booking_id" required>
                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int) $assignment['booking_id'] ?>"><?= $e((string) $assignment['first_name'] . ' ' . (string) $assignment['last_name']) ?> · <?= $e((string) $assignment['locker_name']) ?> · <?= $e((string) $assignment['school_year_label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Art des Problems
                        <select name="category" required>
                            <?php foreach ($categories as $category): ?><option value="<?= $e($category->value) ?>"><?= $e($category->label()) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="wide">Beschreibung
                        <textarea name="description" rows="4" maxlength="4000" required placeholder="Bitte kurz beschreiben, was nicht funktioniert oder warum eine Notöffnung benötigt wird."></textarea>
                    </label>
                </div>
                <div class="alert alert-neutral">Bei Defektmeldungen sperrt FachDock das betroffene Fach vorsorglich für neue Buchungen. Eine bestehende Buchung wird dadurch nicht beendet.</div>
                <button class="button" type="submit">Meldung absenden</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Meine Meldungen</h2>
        <?php if ($incidents === []): ?>
            <p>Noch keine Schließfachmeldungen vorhanden.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($incidents as $incident): $category = LockerIncidentCategory::tryFrom((string) $incident['category']); $status = LockerIncidentStatus::tryFrom((string) $incident['status']); ?>
                    <div class="entity-row">
                        <div>
                            <strong>#<?= (int) $incident['id'] ?> · <?= $e($category?->label() ?? (string) $incident['category']) ?></strong>
                            <div class="muted"><?= $e((string) $incident['student_name']) ?> · Schließfach <?= $e((string) $incident['locker_name']) ?></div>
                            <small>Gemeldet am <?= $e((string) $incident['opened_at']) ?></small>
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

    <div><a class="button button-secondary" href="/parent">Zurück zur Übersicht</a></div>
</main>
</body>
</html>
