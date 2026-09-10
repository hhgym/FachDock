<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var list<array{id: int, label: string, status: string}> $schoolYears */
/** @var list<array<string, mixed>> $bookings */
/** @var array{total:int,page:int,pages:int,page_size:int} $pagination */
/** @var int|null $selectedSchoolYearId */
/** @var string|null $selectedStatus */
/** @var string $query */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (?int $cents): string => $cents === null ? '–' : number_format($cents / 100, 2, ',', '.') . ' €';
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Aktiv',
    'exemption_review' => 'BuT-Prüfung',
    'payment_due' => 'Zahlung offen',
    'ended' => 'Beendet',
    'cancelled' => 'Storniert',
    default => $status,
};
$paymentLabel = static fn (?string $status): string => match ($status) {
    null, '' => '–',
    'creating' => 'Wird angelegt',
    'checkout_open' => 'Checkout offen',
    'processing_paid' => 'Zahlung wird verbucht',
    'paid' => 'Bezahlt',
    'failed' => 'Fehlgeschlagen',
    'expired' => 'Abgelaufen',
    'manual_review' => 'Manuelle Prüfung',
    default => $status,
};
$page = max(1, (int) ($pagination['page'] ?? 1));
$pages = max(1, (int) ($pagination['pages'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? count($bookings)));
$pageSize = max(1, (int) ($pagination['page_size'] ?? 50));
$pageUrl = static function (int $targetPage) use ($selectedSchoolYearId, $selectedStatus, $query): string {
    $parameters = ['page' => max(1, $targetPage)];
    if ($selectedSchoolYearId !== null) {
        $parameters['school_year_id'] = $selectedSchoolYearId;
    }
    if ($selectedStatus !== null && $selectedStatus !== '') {
        $parameters['status'] = $selectedStatus;
    }
    if ($query !== '') {
        $parameters['q'] = $query;
    }

    return '/admin/bookings?' . http_build_query($parameters);
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungen · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungen</span>
        <h1>Buchungsverwaltung</h1>
        <p>Alle verbindlichen Schließfachbuchungen mit Buchungs-, Gebühren- und Zahlungsstatus.</p>
    </header>

    <section class="card stack">
        <h2>Filter</h2>
        <form method="get" action="/admin/bookings" class="grid">
            <label>Schuljahr
                <select name="school_year_id">
                    <option value="">Alle Schuljahre</option>
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?= (int) $year['id'] ?>" <?= $selectedSchoolYearId === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= $e($year['label']) ?><?= $year['status'] === 'active' ? ' · aktiv' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="">Alle Status</option>
                    <?php foreach (['active', 'exemption_review', 'payment_due', 'ended', 'cancelled'] as $status): ?>
                        <option value="<?= $e($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= $e($statusLabel($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide">Suche
                <input type="search" name="q" value="<?= $e($query) ?>" placeholder="Name, Klasse, Matrikelnummer, Fach oder Buchungs-ID">
            </label>
            <div>
                <button class="button" type="submit">Filtern</button>
                <a class="button button-secondary" href="/admin/bookings">Zurücksetzen</a>
            </div>
        </form>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Buchungen</h2>
                <p class="form-hint">Seite <?= $page ?> von <?= $pages ?> · bis zu <?= $pageSize ?> Einträge pro Seite.</p>
            </div>
            <span class="badge"><?= $total ?> Treffer</span>
        </div>

        <?php if ($bookings === []): ?>
            <p>Für die gewählten Filter wurden keine Buchungen gefunden.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th><th>Schüler</th><th>Schuljahr</th><th>Fach</th><th>Buchung</th><th>Gebühr</th><th>Zahlung</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td>#<?= (int) $booking['id'] ?></td>
                            <td><strong><?= $e((string) $booking['first_name'] . ' ' . (string) $booking['last_name']) ?></strong><br><span class="muted"><?= $e((string) $booking['class_name']) ?> · <?= $e((string) $booking['matrikelnummer']) ?></span></td>
                            <td><?= $e((string) $booking['school_year_label']) ?></td>
                            <td><?= $booking['locker_short_name'] !== null ? $e((string) $booking['locker_short_name']) : '–' ?></td>
                            <td><span class="badge"><?= $e($statusLabel((string) $booking['status'])) ?></span><br><span class="muted"><?= $e((string) $booking['created_at']) ?></span></td>
                            <td><?= $e($money($booking['charged_fee_cents'] !== null ? (int) $booking['charged_fee_cents'] : null)) ?><?php if ($booking['fee_exemption_type'] !== null): ?><br><span class="muted"><?= $e((string) $booking['fee_exemption_type']) ?></span><?php endif; ?></td>
                            <td><?= $e($paymentLabel($booking['payment_status'] !== null ? (string) $booking['payment_status'] : null)) ?></td>
                            <td><a href="/admin/bookings/detail?id=<?= (int) $booking['id'] ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten der Buchungsliste">
                <?php if ($page > 1): ?>
                    <a class="button button-secondary" href="<?= $e($pageUrl(1)) ?>">Erste</a>
                    <a class="button button-secondary" rel="prev" href="<?= $e($pageUrl($page - 1)) ?>">Zurück</a>
                <?php endif; ?>
                <span class="pagination-status">Seite <?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="button button-secondary" rel="next" href="<?= $e($pageUrl($page + 1)) ?>">Weiter</a>
                    <a class="button button-secondary" href="<?= $e($pageUrl($pages)) ?>">Letzte</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
</body>
</html>