<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var list<array{booking_id: int, student_id: int, student_name: string, class_name: string, school_year_label: string, locker_short_name: string, parent_email: string|null, annual_fee_cents: int, proration_months: int, fallback_fee_cents: int, created_at: string}> $claims */
/** @var string $csrfToken */
/** @var list<string> $errors */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BuT-Prüfung · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <div><strong>FachDock</strong> · BuT-Prüfung</div>
    <div class="topbar-actions"><a href="/">Dashboard</a><span><?= $e($staff->displayName) ?></span></div>
</header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungen</span>
        <h1>Offene BuT-Befreiungsprüfungen</h1>
        <p>Ein Antrag hat bereits eine verbindliche Buchung erzeugt. Bei Ablehnung wird der zum Buchungszeitpunkt ermittelte Beitrag mit der konfigurierten Zahlungsfrist fällig.</p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="card stack">
        <div class="school-year-heading">
            <h2>Offene Prüfungen</h2>
            <span class="badge"><?= count($claims) ?> offen</span>
        </div>
        <?php if ($claims === []): ?>
            <p>Aktuell liegen keine offenen BuT-Prüfungen vor.</p>
        <?php else: ?>
            <div class="entity-list">
                <?php foreach ($claims as $claim): ?>
                    <details class="entity-row">
                        <summary>
                            <strong><?= $e((string) $claim['student_name']) ?></strong>
                            · Klasse <?= $e((string) $claim['class_name']) ?>
                            · <?= $e((string) $claim['school_year_label']) ?>
                            · Fach <?= $e((string) $claim['locker_short_name']) ?>
                        </summary>
                        <div class="compact-form stack">
                            <p class="form-hint">Antrag/Buchung seit <?= $e((string) $claim['created_at']) ?> · Elternkontakt <?= $claim['parent_email'] !== null ? $e((string) $claim['parent_email']) : '–' ?></p>
                            <p>Jahresbeitrag: <?= $e($money((int) $claim['annual_fee_cents'])) ?> · berücksichtigt: <?= (int) $claim['proration_months'] ?> Monate · bei Ablehnung fällig: <strong><?= $e($money((int) $claim['fallback_fee_cents'])) ?></strong></p>

                            <form method="post" action="/admin/but/approve" class="stack">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int) $claim['booking_id'] ?>">
                                <label>Prüfvermerk
                                    <textarea name="note" rows="2" maxlength="4000" required placeholder="z. B. Nachweis geprüft und anerkannt"></textarea>
                                </label>
                                <button class="button" type="submit">BuT-Befreiung bestätigen</button>
                            </form>

                            <form method="post" action="/admin/but/reject" class="stack">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="booking_id" value="<?= (int) $claim['booking_id'] ?>">
                                <label>Begründung der Ablehnung
                                    <textarea name="note" rows="2" maxlength="4000" required placeholder="z. B. Nachweis nicht ausreichend"></textarea>
                                </label>
                                <button class="button button-secondary" type="submit">Ablehnen und Zahlung fällig stellen</button>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
