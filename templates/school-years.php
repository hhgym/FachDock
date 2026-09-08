<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;
use FachDock\SchoolYear\SchoolYearStatus;

/** @var AuthenticatedStaff $staff */
/** @var string $csrfToken */
/** @var list<string> $errors */
/** @var list<array<string, mixed>> $schoolYears */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.');
$date = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '–';
    }

    return (new DateTimeImmutable($value))->format('d.m.Y');
};
$dateTime = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '–';
    }

    return (new DateTimeImmutable($value))->format('d.m.Y H:i');
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schuljahre · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · Schuljahre</div>
    <div class="topbar-actions"><a href="/">Dashboard</a></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungskonfiguration</span>
        <h1>Schuljahre verwalten</h1>
        <p>Zeiträume, Buchungsöffnung, Jahresbeitrag und die zulässige Zahl eigenständiger Fachwechsel festlegen.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Automatische Vorbereitung</h2>
        <p class="form-hint">Legt das aktuelle und das folgende Schuljahr an, sofern sie noch fehlen. Zeiträume werden immer als 01.08.–31.07. erzeugt.</p>
        <form method="post" action="/admin/school-years/ensure">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <button class="button" type="submit">Aktuelles und nächstes Schuljahr sicherstellen</button>
        </form>
    </section>

    <section class="card stack">
        <h2>Weiteres Schuljahr anlegen</h2>
        <form method="post" action="/admin/school-years/create" class="grid">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label>Startjahr<input name="start_year" required inputmode="numeric" placeholder="2027"><small>Für 2027/28: 2027</small></label>
            <label>Jahresbeitrag in €<input name="annual_fee" required inputmode="decimal" value="0,00"></label>
            <label>Max. Eltern-Fachwechsel<input name="max_parent_changes" required inputmode="numeric" value="2" min="0" max="100"></label>
            <button class="button" type="submit">Schuljahr anlegen</button>
        </form>
    </section>

    <section class="stack">
        <?php if ($schoolYears === []): ?>
            <div class="card"><p>Noch keine Schuljahre vorhanden.</p></div>
        <?php endif; ?>

        <?php foreach ($schoolYears as $year): ?>
            <?php
            $status = SchoolYearStatus::tryFrom((string) $year['status']);
            $editable = (bool) $year['editable'];
            ?>
            <article class="card stack">
                <div class="school-year-heading">
                    <div>
                        <span class="eyebrow">Schuljahr</span>
                        <h2><?= $e((string) $year['label']) ?></h2>
                        <p class="form-hint"><?= $e($date((string) $year['starts_on'])) ?> bis <?= $e($date((string) $year['ends_on'])) ?></p>
                    </div>
                    <span class="badge"><?= $e($status?->label() ?? (string) $year['status']) ?></span>
                </div>

                <?php if ((string) $year['status'] === SchoolYearStatus::Closed->value): ?>
                    <?php if ($year['reopened_until'] !== null && $editable): ?>
                        <div class="alert alert-success">
                            Für Korrekturen geöffnet bis <?= $e($dateTime((string) $year['reopened_until'])) ?>.
                            <?php if ($year['reopened_by_name'] !== null): ?>Geöffnet von <?= $e((string) $year['reopened_by_name']) ?>.<?php endif; ?>
                            <?php if ($year['reopen_reason'] !== null): ?><br>Grund: <?= $e((string) $year['reopen_reason']) ?><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="form-hint">Geschlossen<?= $year['closed_at'] !== null ? ' seit ' . $e($dateTime((string) $year['closed_at'])) : '' ?>. Änderungen an den Geschäftseinstellungen sind gesperrt.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="post" action="/admin/school-years/update" class="grid">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                    <label>Neubuchungen ab
                        <input type="date" name="new_booking_opens_on" required value="<?= $e((string) $year['new_booking_opens_on']) ?>" <?= $editable ? '' : 'disabled' ?>>
                    </label>
                    <label>Jahresbeitrag in €
                        <input name="annual_fee" required inputmode="decimal" value="<?= $e($money((int) $year['annual_fee_cents'])) ?>" <?= $editable ? '' : 'disabled' ?>>
                    </label>
                    <label>Max. Eltern-Fachwechsel
                        <input name="max_parent_changes" required inputmode="numeric" min="0" max="100" value="<?= (int) $year['max_parent_changes'] ?>" <?= $editable ? '' : 'disabled' ?>>
                    </label>
                    <button class="button" type="submit" <?= $editable ? '' : 'disabled' ?>>Einstellungen speichern</button>
                </form>

                <div class="school-year-actions">
                    <?php if ((string) $year['status'] !== SchoolYearStatus::Closed->value): ?>
                        <form method="post" action="/admin/school-years/close" class="grid" onsubmit="return confirm('Schuljahr wirklich schließen?');">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                            <label class="wide">Begründung<input name="reason" required maxlength="500" placeholder="z. B. regulärer Jahresabschluss"></label>
                            <button class="button button-secondary" type="submit">Schuljahr schließen</button>
                        </form>
                    <?php elseif ($year['reopened_until'] !== null && $editable): ?>
                        <form method="post" action="/admin/school-years/reopen/end">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                            <button class="button button-secondary" type="submit">Korrekturöffnung jetzt beenden</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/admin/school-years/reopen" class="grid">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="school_year_id" value="<?= (int) $year['id'] ?>">
                            <label>Dauer in Minuten<input name="minutes" required inputmode="numeric" min="5" max="1440" value="60"></label>
                            <label class="wide">Begründung<input name="reason" required maxlength="500" placeholder="Welche Korrektur ist erforderlich?"></label>
                            <button class="button button-secondary" type="submit">Zur Korrektur öffnen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
