<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Location\LockerOperatingStatus;
use FachDock\Operations\LockerIncidentCategory;
use FachDock\Operations\LockerIncidentStatus;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var bool $success */
/** @var string $statusFilter */
/** @var list<array<string, mixed>> $incidents */
/** @var array<string, mixed>|null $detail */
/** @var list<array<string, mixed>> $events */
/** @var list<array<string, mixed>> $operationHistory */
/** @var list<array<string, mixed>> $lockers */
/** @var list<LockerIncidentCategory> $categories */
/** @var list<LockerIncidentStatus> $incidentStatuses */
/** @var list<LockerOperatingStatus> $operatingStatuses */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$categoryLabel = static function (string $value): string {
    return LockerIncidentCategory::tryFrom($value)?->label() ?? $value;
};
$statusLabel = static function (string $value): string {
    return LockerIncidentStatus::tryFrom($value)?->label() ?? $value;
};
$operatingLabel = static function (string $value): string {
    return LockerOperatingStatus::tryFrom($value)?->label() ?? $value;
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schließfachbetrieb · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><div><strong>FachDock</strong> · Schließfachbetrieb</div></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Betrieb</span>
        <h1>Defekte, Notöffnungen und Wartung</h1>
        <p>Vorgänge bearbeiten, Notöffnungen dokumentieren und den technischen Betriebsstatus einzelner Schließfächer verwalten.</p>
    </header>

    <?php if ($success): ?><div class="alert alert-success">Die Änderung wurde gespeichert.</div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <div><h2>Vorgänge</h2><p class="form-hint">Dringende Notöffnungen werden zuerst angezeigt.</p></div>
            <div class="compact-actions">
                <a class="button button-secondary" href="/admin/operations">Alle</a>
                <?php foreach ($incidentStatuses as $incidentStatus): ?>
                    <a class="button button-secondary" href="/admin/operations?status=<?= $e($incidentStatus->value) ?>"><?= $e($incidentStatus->label()) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($incidents === []): ?>
            <p>Für diesen Filter liegen keine Vorgänge vor.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($incidents as $incident): ?>
                    <div class="entity-row">
                        <div>
                            <strong>#<?= (int) $incident['id'] ?> · <?= $e($categoryLabel((string) $incident['category'])) ?></strong>
                            <div class="muted">Schließfach <?= $e((string) $incident['locker_name']) ?> · <?= $e((string) $incident['student_name']) ?><?= trim((string) ($incident['class_name'] ?? '')) !== '' ? ' · Klasse ' . $e((string) $incident['class_name']) : '' ?></div>
                            <small><?= $e((string) $incident['opened_at']) ?> · <?= $e($statusLabel((string) $incident['status'])) ?><?= (string) $incident['priority'] === 'urgent' ? ' · DRINGEND' : '' ?></small>
                        </div>
                        <a class="button button-secondary" href="/admin/operations?incident_id=<?= (int) $incident['id'] ?><?= $statusFilter !== '' ? '&amp;status=' . $e($statusFilter) : '' ?>">Öffnen</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($detail !== null): ?>
        <?php $detailCategory = LockerIncidentCategory::tryFrom((string) $detail['category']); ?>
        <section class="card stack">
            <div class="school-year-heading">
                <div>
                    <span class="eyebrow">Vorgang #<?= (int) $detail['id'] ?></span>
                    <h2><?= $e($categoryLabel((string) $detail['category'])) ?> · <?= $e((string) $detail['locker_name']) ?></h2>
                    <p><?= $e((string) $detail['student_name']) ?><?= trim((string) ($detail['class_name'] ?? '')) !== '' ? ' · Klasse ' . $e((string) $detail['class_name']) : '' ?></p>
                </div>
                <span class="badge"><?= $e($statusLabel((string) $detail['status'])) ?></span>
            </div>
            <div class="alert alert-neutral"><?= nl2br($e((string) $detail['description'])) ?></div>
            <div class="settings-status-grid">
                <div class="settings-status-item"><strong>Gemeldet durch</strong><span><?= $e((string) $detail['reported_by_type']) ?><?= trim((string) ($detail['reporter_name'] ?? '')) !== '' ? ' · ' . $e((string) $detail['reporter_name']) : '' ?></span></div>
                <div class="settings-status-item"><strong>Betriebsstatus</strong><span><?= $e($operatingLabel((string) $detail['operating_status'])) ?></span></div>
                <div class="settings-status-item"><strong>Buchbar</strong><span><?= (int) $detail['bookable'] === 1 ? 'ja' : 'nein' ?></span></div>
                <div class="settings-status-item"><strong>Standort</strong><span><?= $e((string) $detail['building_name']) ?> · <?= $e((string) $detail['floor_name']) ?> · <?= $e((string) $detail['area_name']) ?></span></div>
            </div>

            <form class="stack" method="post" action="/admin/operations/update">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="incident_id" value="<?= (int) $detail['id'] ?>">
                <div class="grid">
                    <label>Status
                        <select name="status" required>
                            <?php foreach ($incidentStatuses as $incidentStatus): ?>
                                <option value="<?= $e($incidentStatus->value) ?>" <?= $incidentStatus->value === (string) $detail['status'] ? 'selected' : '' ?>><?= $e($incidentStatus->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="wide">Bearbeitungs-/Abschlussvermerk
                        <textarea name="note" rows="3" maxlength="4000" placeholder="Was wurde geprüft oder erledigt?"></textarea>
                    </label>
                </div>
                <button class="button" type="submit">Vorgang aktualisieren</button>
            </form>

            <?php if (in_array($detailCategory, [LockerIncidentCategory::EmergencyOpening, LockerIncidentCategory::CodeForgotten, LockerIncidentCategory::LockProblem], true) && (string) $detail['status'] !== LockerIncidentStatus::Resolved->value): ?>
                <form class="stack" method="post" action="/admin/operations/emergency-opening">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="incident_id" value="<?= (int) $detail['id'] ?>">
                    <h3>Notöffnung dokumentieren</h3>
                    <label>Dokumentation
                        <textarea name="note" rows="3" maxlength="4000" required placeholder="z. B. Notschlüssel verwendet, Fach gemeinsam mit Schüler geöffnet"></textarea>
                    </label>
                    <button class="button" type="submit">Notöffnung als durchgeführt dokumentieren</button>
                </form>
            <?php endif; ?>

            <form class="stack" method="post" action="/admin/operations/locker-status">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="incident_id" value="<?= (int) $detail['id'] ?>">
                <input type="hidden" name="locker_id" value="<?= (int) $detail['locker_id'] ?>">
                <h3>Technischen Zustand ändern</h3>
                <div class="grid">
                    <label>Betriebsstatus
                        <select name="operating_status" required>
                            <?php foreach ($operatingStatuses as $operatingStatus): ?>
                                <option value="<?= $e($operatingStatus->value) ?>" <?= $operatingStatus->value === (string) $detail['operating_status'] ? 'selected' : '' ?>><?= $e($operatingStatus->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><input type="checkbox" name="bookable" value="1" <?= (int) $detail['bookable'] === 1 ? 'checked' : '' ?>> Für neue Buchungen freigeben</label>
                    <label class="wide">Begründung
                        <textarea name="note" rows="2" maxlength="4000" required></textarea>
                    </label>
                </div>
                <p class="form-hint">Bei Defekt, Sperre, Wartung oder „Außer Betrieb“ setzt FachDock die Buchbarkeit unabhängig vom Häkchen auf „nein“.</p>
                <button class="button button-secondary" type="submit">Betriebsstatus speichern</button>
            </form>
        </section>

        <section class="card stack">
            <h2>Vorgangshistorie</h2>
            <div class="entity-list">
                <?php foreach ($events as $event): ?>
                    <div class="entity-row">
                        <div><strong><?= $e((string) $event['event_type']) ?></strong><div class="muted"><?= $e((string) $event['created_at']) ?> · <?= $e((string) $event['actor_type']) ?></div></div>
                        <div><?= nl2br($e((string) ($event['note'] ?? ''))) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card stack">
            <h2>Technische Historie von <?= $e((string) $detail['locker_name']) ?></h2>
            <?php if ($operationHistory === []): ?><p>Noch keine technischen Statusänderungen protokolliert.</p><?php else: ?>
                <div class="entity-list">
                    <?php foreach ($operationHistory as $event): ?>
                        <div class="entity-row">
                            <div><strong><?= $e((string) $event['event_type']) ?></strong><div class="muted"><?= $e((string) $event['created_at']) ?></div></div>
                            <div><?= $e($operatingLabel((string) ($event['old_operating_status'] ?? ''))) ?> → <?= $e($operatingLabel((string) ($event['new_operating_status'] ?? ''))) ?><br><small><?= $e((string) ($event['note'] ?? '')) ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card stack">
        <h2>Vorgang durch die Schließfachverwaltung anlegen</h2>
        <form class="stack" method="post" action="/admin/operations/report">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <div class="grid">
                <label>Schließfach
                    <select name="locker_id" required>
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($lockers as $locker): ?>
                            <option value="<?= (int) $locker['id'] ?>"><?= $e((string) $locker['short_name']) ?> · <?= $e($operatingLabel((string) $locker['operating_status'])) ?><?= isset($locker['student_id']) ? ' · ' . $e((string) $locker['first_name'] . ' ' . (string) $locker['last_name']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Art
                    <select name="category" required>
                        <?php foreach ($categories as $category): ?><option value="<?= $e($category->value) ?>"><?= $e($category->label()) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="wide">Beschreibung
                    <textarea name="description" rows="3" maxlength="4000" required></textarea>
                </label>
            </div>
            <button class="button" type="submit">Vorgang anlegen</button>
        </form>
    </section>

    <section class="card stack">
        <h2>Schließfachstatus</h2>
        <p class="form-hint">Für schnelle technische Änderungen ohne konkreten Vorgang.</p>
        <form class="stack" method="post" action="/admin/operations/locker-status">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <div class="grid">
                <label>Schließfach
                    <select name="locker_id" required>
                        <option value="">Bitte auswählen</option>
                        <?php foreach ($lockers as $locker): ?><option value="<?= (int) $locker['id'] ?>"><?= $e((string) $locker['short_name']) ?> · <?= $e($operatingLabel((string) $locker['operating_status'])) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Betriebsstatus
                    <select name="operating_status" required>
                        <?php foreach ($operatingStatuses as $operatingStatus): ?><option value="<?= $e($operatingStatus->value) ?>"><?= $e($operatingStatus->label()) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label><input type="checkbox" name="bookable" value="1"> Für neue Buchungen freigeben</label>
                <label class="wide">Begründung
                    <textarea name="note" rows="2" maxlength="4000" required></textarea>
                </label>
            </div>
            <button class="button button-secondary" type="submit">Status ändern</button>
        </form>
    </section>
</main>
</body>
</html>
