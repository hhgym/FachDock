<?php

declare(strict_types=1);

use FachDock\Auth\AuthenticatedStaff;

/** @var AuthenticatedStaff $staff */
/** @var list<array{id: int, label: string, status: string}> $schoolYears */
/** @var list<array<string, mixed>> $payments */
/** @var array{total:int,page:int,pages:int,page_size:int} $pagination */
/** @var int|null $selectedSchoolYearId */
/** @var string|null $selectedStatus */
/** @var string $query */
/** @var string $csrfToken */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => number_format($cents / 100, 2, ',', '.') . ' ' . $currency;
$statusLabel = static fn (string $status): string => match ($status) {
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
$total = max(0, (int) ($pagination['total'] ?? count($payments)));
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

    return '/admin/payments?' . http_build_query($parameters);
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahlungen · FachDock</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar"><strong>FachDock</strong></header>
<main class="shell stack">
    <header class="hero">
        <span class="eyebrow">Buchungen</span>
        <h1>Zahlungsverwaltung</h1>
        <p>Stripe-Zahlungsversuche einschließlich Checkout-, Webhook- und Fehlerstatus.</p>
    </header>

    <?php if (array_filter($payments, static fn (array $payment): bool => (string) $payment['status'] === 'manual_review') !== []): ?>
        <div class="alert alert-error">
            <strong>Manuelle Prüfung erforderlich.</strong>
            Mindestens eine von Stripe bestätigte Zahlung konnte nicht automatisch in eine Buchung überführt werden.
        </div>
    <?php endif; ?>

    <section class="card stack">
        <h2>Filter</h2>
        <form method="get" action="/admin/payments" class="grid">
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
                    <?php foreach (['checkout_open', 'processing_paid', 'paid', 'failed', 'expired', 'manual_review', 'creating'] as $status): ?>
                        <option value="<?= $e($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>><?= $e($statusLabel($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide">Suche
                <input type="search" name="q" value="<?= $e($query) ?>" placeholder="Name, E-Mail, Klasse, Fach, Payment-ID, Session oder PaymentIntent">
            </label>
            <div>
                <button class="button" type="submit">Filtern</button>
                <a class="button button-secondary" href="/admin/payments">Zurücksetzen</a>
            </div>
        </form>
    </section>

    <section class="card stack">
        <div class="school-year-heading">
            <div>
                <h2>Zahlungsvorgänge</h2>
                <p class="form-hint">Seite <?= $page ?> von <?= $pages ?> · bis zu <?= $pageSize ?> Einträge pro Seite.</p>
            </div>
            <span class="badge"><?= $total ?> Treffer</span>
        </div>

        <?php if ($payments === []): ?>
            <p>Für die gewählten Filter wurden keine Zahlungsvorgänge gefunden.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th><th>Schüler</th><th>Elternkonto</th><th>Schuljahr / Fach</th><th>Betrag</th><th>Status</th><th>Webhook</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>#<?= (int) $payment['id'] ?></td>
                            <td><strong><?= $e((string) $payment['first_name'] . ' ' . (string) $payment['last_name']) ?></strong><br><span class="muted"><?= $e((string) $payment['class_name']) ?></span></td>
                            <td><?= $e((string) $payment['parent_email']) ?></td>
                            <td><?= $e((string) $payment['school_year_label']) ?><br><span class="muted"><?= $e((string) $payment['locker_short_name']) ?></span></td>
                            <td><?= $e($money((int) $payment['amount_cents'], (string) $payment['currency'])) ?></td>
                            <td><span class="badge"><?= $e($statusLabel((string) $payment['status'])) ?></span><?php if ($payment['failure_code'] !== null): ?><br><span class="muted"><?= $e((string) $payment['failure_code']) ?></span><?php endif; ?></td>
                            <td><?= (int) $payment['webhook_open_count'] > 0 ? '<strong>' . (int) $payment['webhook_open_count'] . ' offen</strong>' : 'OK' ?></td>
                            <td><a href="/admin/payments/detail?id=<?= (int) $payment['id'] ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten der Zahlungsliste">
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