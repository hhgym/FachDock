<?php

declare(strict_types=1);

namespace FachDock\View;

final class LockerGridRenderer
{
    /**
     * @param list<array<string, mixed>> $lockers
     * @return list<array<string, mixed>>
     */
    public static function groups(array $lockers, string $idKey = 'id'): array
    {
        $groups = [];
        foreach ($lockers as $locker) {
            $id = (int) ($locker[$idKey] ?? 0);
            $shortName = trim((string) ($locker['short_name'] ?? $locker['locker_short_name'] ?? ''));
            if ($id < 1 || $shortName === '') {
                continue;
            }

            $parsed = self::parseShortName($shortName);
            $groupCode = trim((string) ($locker['group_code'] ?? $parsed['group_code']));
            $corpusPosition = (int) ($locker['corpus_position'] ?? $parsed['corpus_position']);
            $lockerPosition = (int) ($locker['locker_position'] ?? $parsed['locker_position']);
            if ($groupCode === '' || $corpusPosition < 1 || $lockerPosition < 1) {
                continue;
            }

            $building = trim((string) ($locker['building_name'] ?? ''));
            $floor = trim((string) ($locker['floor_name'] ?? ''));
            $area = trim((string) ($locker['area_name'] ?? ''));
            $key = implode('|', [$building, $floor, $area, $groupCode]);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'group_code' => $groupCode,
                    'building_name' => $building,
                    'floor_name' => $floor,
                    'area_name' => $area,
                    'corpuses' => [],
                    'max_locker_position' => 0,
                    'locker_count' => 0,
                ];
            }

            $locker['_grid_id'] = $id;
            $locker['_grid_short_name'] = $shortName;
            $locker['_grid_corpus_position'] = $corpusPosition;
            $locker['_grid_locker_position'] = $lockerPosition;
            $groups[$key]['corpuses'][$corpusPosition][$lockerPosition] = $locker;
            $groups[$key]['max_locker_position'] = max(
                (int) $groups[$key]['max_locker_position'],
                $lockerPosition,
            );
            ++$groups[$key]['locker_count'];
        }

        $result = array_values($groups);
        foreach ($result as &$group) {
            ksort($group['corpuses'], SORT_NUMERIC);
            foreach ($group['corpuses'] as &$corpus) {
                ksort($corpus, SORT_NUMERIC);
            }
            unset($corpus);
        }
        unset($group);

        usort($result, static function (array $left, array $right): int {
            foreach (['building_name', 'floor_name', 'area_name', 'group_code'] as $key) {
                $comparison = strnatcasecmp((string) $left[$key], (string) $right[$key]);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $lockers
     * @param array<string, scalar> $hiddenFields
     */
    public static function formGrid(
        array $lockers,
        string $action,
        array $hiddenFields,
        string $idKey = 'locker_id',
        string $buttonLabel = 'Auswählen',
        bool $disabled = false,
    ): string {
        return self::render($lockers, $idKey, function (array $locker) use (
            $action,
            $hiddenFields,
            $buttonLabel,
            $disabled,
        ): string {
            $hidden = '';
            foreach ($hiddenFields as $name => $value) {
                $hidden .= '<input type="hidden" name="' . self::e((string) $name) . '" value="'
                    . self::e((string) $value) . '">';
            }

            return '<form method="post" action="' . self::e($action) . '">'
                . $hidden
                . '<input type="hidden" name="locker_id" value="' . (int) $locker['_grid_id'] . '">'
                . '<button class="locker-grid-cell" type="submit"'
                . ($disabled ? ' disabled' : '')
                . ' title="' . self::e(self::description($locker)) . '">'
                . '<strong>' . self::e((string) $locker['_grid_short_name']) . '</strong>'
                . '<small>' . self::e($buttonLabel) . '</small>'
                . '</button></form>';
        });
    }

    /** @param list<array<string, mixed>> $lockers */
    public static function pickerGrid(
        array $lockers,
        string $targetSelectId,
        string $idKey = 'id',
        ?int $selectedLockerId = null,
    ): string {
        return self::render($lockers, $idKey, static function (array $locker) use (
            $targetSelectId,
            $selectedLockerId,
        ): string {
            $id = (int) $locker['_grid_id'];
            $selected = $selectedLockerId === $id;

            return '<button class="locker-grid-cell' . ($selected ? ' is-selected' : '') . '" type="button" '
                . 'data-locker-grid-choice="' . $id . '" data-locker-grid-target="' . self::e($targetSelectId) . '" '
                . 'title="' . self::e(self::description($locker)) . '">'
                . '<strong>' . self::e((string) $locker['_grid_short_name']) . '</strong>'
                . '<small>' . ($selected ? 'ausgewählt' : 'wählen') . '</small>'
                . '</button>';
        });
    }

    public static function statusLegend(): string
    {
        $html = '<div class="locker-status-legend" aria-label="Legende">';
        foreach (['free', 'reserved', 'occupied', 'issue', 'unavailable'] as $status) {
            $html .= '<span class="locker-status-key"><span class="locker-status-dot '
                . self::statusClass($status) . '"></span>' . self::e(self::statusLabel($status)) . '</span>';
        }

        return $html . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $lockers
     * @param callable(array<string, mixed>, string, string): string $actions
     * @param array<int, bool> $recommendedLockerIds
     * @param array<int, int> $scores
     */
    public static function statusViews(
        array $lockers,
        callable $actions,
        array $recommendedLockerIds = [],
        array $scores = [],
        string $idKey = 'locker_id',
    ): string {
        $groups = self::groups($lockers, $idKey);
        if ($groups === []) {
            return '<p class="form-hint">Für diese Auswahl kann kein Raster dargestellt werden.</p>';
        }

        $html = '<div class="locker-status-module">';
        foreach ($groups as $group) {
            $groupLockers = [];
            $counts = ['free' => 0, 'reserved' => 0, 'occupied' => 0, 'issue' => 0, 'unavailable' => 0];
            foreach ($group['corpuses'] as $corpus) {
                foreach ($corpus as $locker) {
                    $groupLockers[] = $locker;
                    $status = self::normalizedStatus((string) ($locker['availability_status'] ?? 'unavailable'));
                    ++$counts[$status];
                }
            }
            usort($groupLockers, static function (array $left, array $right): int {
                return [(int) $left['_grid_corpus_position'], (int) $left['_grid_locker_position']]
                    <=> [(int) $right['_grid_corpus_position'], (int) $right['_grid_locker_position']];
            });

            $location = array_values(array_filter([
                (string) $group['building_name'],
                (string) $group['floor_name'],
                (string) $group['area_name'],
            ], static fn (string $value): bool => $value !== ''));

            $html .= '<section class="locker-management-group">'
                . '<header><div><span class="eyebrow">Schrankgruppe</span><h2>'
                . self::e((string) $group['group_code']) . '</h2><p class="form-hint">'
                . self::e(implode(' · ', $location)) . '</p></div><div class="locker-group-meta">';
            foreach (['free', 'reserved', 'occupied', 'issue', 'unavailable'] as $status) {
                if ($counts[$status] === 0 && $status === 'unavailable') {
                    continue;
                }
                $html .= '<span class="locker-status-pill ' . self::statusClass($status) . '">'
                    . $counts[$status] . ' ' . self::e(mb_strtolower(self::statusLabel($status))) . '</span>';
            }
            $html .= '</div></header><div class="locker-group-views">';

            $html .= '<details open><summary>Rasteransicht</summary><div class="locker-group-view-body">'
                . '<div class="locker-grid-scroll"><table class="locker-grid-table locker-status-grid"><thead><tr>'
                . '<th>Fach</th>';
            foreach (array_keys($group['corpuses']) as $corpusPosition) {
                $html .= '<th>Korpus ' . str_pad((string) $corpusPosition, 2, '0', STR_PAD_LEFT) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            for ($position = 1; $position <= (int) $group['max_locker_position']; ++$position) {
                $html .= '<tr><th>Position ' . $position . '</th>';
                foreach ($group['corpuses'] as $corpus) {
                    $locker = $corpus[$position] ?? null;
                    $html .= '<td>';
                    if (is_array($locker)) {
                        $html .= self::statusGridCell(
                            $locker,
                            $actions,
                            $recommendedLockerIds,
                            $scores,
                        );
                    } else {
                        $html .= '<span class="locker-grid-empty" aria-label="kein Schließfach">–</span>';
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div></div></details>';

            $html .= '<details><summary>Listenansicht</summary><div class="locker-group-view-body table-scroll">'
                . '<table class="data-table locker-status-list"><thead><tr><th>Fach</th><th>Status</th>'
                . '<th>Schüler</th><th>Merkmale</th><th>Aktionen</th></tr></thead><tbody>';
            foreach ($groupLockers as $locker) {
                $status = self::normalizedStatus((string) ($locker['availability_status'] ?? 'unavailable'));
                $lockerId = (int) $locker['_grid_id'];
                $person = self::personHtml($locker, false);
                $detail = self::statusDetail($locker, $status);
                $actionHtml = (string) $actions($locker, 'list', $status);
                $features = [];
                if (!empty($locker['barrier_friendly'])) {
                    $features[] = 'barrierearm';
                }
                if (isset($scores[$lockerId])) {
                    $features[] = 'Score ' . (int) $scores[$lockerId];
                }
                if ($detail !== '') {
                    $features[] = $detail;
                }

                $html .= '<tr><td><code>' . self::e((string) $locker['_grid_short_name']) . '</code>';
                if (isset($locker['long_name'])) {
                    $html .= '<br><small>' . self::e((string) $locker['long_name']) . '</small>';
                }
                $html .= '</td><td class="locker-list-status"><span class="locker-list-state">'
                    . '<span class="locker-status-dot ' . self::statusClass($status) . '"></span>'
                    . self::e(self::statusLabel($status)) . '</span>';
                if (isset($recommendedLockerIds[$lockerId])) {
                    $html .= ' <span class="badge locker-recommendation-badge">Empfohlen</span>';
                }
                $html .= '</td><td class="locker-list-person">' . ($person !== '' ? $person : '—') . '</td>'
                    . '<td>' . ($features !== [] ? self::e(implode(' · ', $features)) : '–') . '</td>'
                    . '<td class="locker-list-actions">' . ($actionHtml !== '' ? $actionHtml : '—') . '</td></tr>';
            }
            $html .= '</tbody></table></div></details></div></section>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<string, mixed> $locker
     * @param callable(array<string, mixed>, string, string): string $actions
     * @param array<int, bool> $recommendedLockerIds
     * @param array<int, int> $scores
     */
    private static function statusGridCell(
        array $locker,
        callable $actions,
        array $recommendedLockerIds,
        array $scores,
    ): string {
        $status = self::normalizedStatus((string) ($locker['availability_status'] ?? 'unavailable'));
        $lockerId = (int) $locker['_grid_id'];
        $person = self::personHtml($locker, true);
        $detail = self::statusDetail($locker, $status);
        $actionHtml = (string) $actions($locker, 'grid', $status);

        $html = '<div class="locker-status-cell ' . self::statusClass($status) . '">'
            . '<div class="locker-status-cell-heading"><code>'
            . self::e((string) $locker['_grid_short_name']) . '</code>';
        if (isset($recommendedLockerIds[$lockerId])) {
            $html .= '<span class="badge locker-recommendation-badge">Empfohlen</span>';
        }
        $html .= '</div><span class="locker-status-pill ' . self::statusClass($status) . '">'
            . self::e(self::statusLabel($status)) . '</span>';
        if ($person !== '') {
            $html .= $person;
        }
        if ($detail !== '') {
            $html .= '<small class="locker-status-detail">' . self::e($detail) . '</small>';
        } elseif (isset($scores[$lockerId]) && $status === 'free') {
            $html .= '<small class="locker-status-detail">Score ' . (int) $scores[$lockerId] . '</small>';
        }
        if ($actionHtml !== '') {
            $html .= '<div class="locker-admin-actions">' . $actionHtml . '</div>';
        }

        return $html . '</div>';
    }

    /** @param array<string, mixed> $locker */
    private static function personHtml(array $locker, bool $compact): string
    {
        if ((int) ($locker['occupied_student_id'] ?? 0) > 0) {
            return self::person(
                (string) ($locker['occupied_last_name'] ?? ''),
                (string) ($locker['occupied_first_name'] ?? ''),
                (string) ($locker['occupied_class_name'] ?? ''),
                $compact,
            );
        }
        if ((int) ($locker['reserved_student_id'] ?? 0) > 0) {
            return self::person(
                (string) ($locker['reserved_last_name'] ?? ''),
                (string) ($locker['reserved_first_name'] ?? ''),
                (string) ($locker['reserved_class_name'] ?? ''),
                $compact,
            );
        }

        return '';
    }

    private static function person(string $lastName, string $firstName, string $className, bool $compact): string
    {
        $name = trim($lastName . ($lastName !== '' && $firstName !== '' ? ', ' : '') . $firstName);
        if (!$compact) {
            return self::e($name) . ($className !== '' ? '<br><small>' . self::e($className) . '</small>' : '');
        }

        return '<small class="locker-status-person"><strong>' . self::e($name) . '</strong>'
            . ($className !== '' ? '<br>' . self::e($className) : '') . '</small>';
    }

    /** @param array<string, mixed> $locker */
    private static function statusDetail(array $locker, string $status): string
    {
        if ($status === 'issue') {
            $count = (int) ($locker['open_issue_count'] ?? 0);
            if ($count > 0) {
                return $count === 1 ? '1 offene Schadensmeldung' : $count . ' offene Schadensmeldungen';
            }

            return 'Defekt';
        }
        if ($status === 'reserved') {
            if ((string) ($locker['reservation_status'] ?? '') === 'payment_running') {
                return 'Zahlung läuft';
            }
            $expiresAt = trim((string) ($locker['reservation_expires_at'] ?? ''));

            return $expiresAt !== '' ? 'bis ' . $expiresAt : '';
        }
        if ($status === 'unavailable') {
            return self::operatingStatusLabel((string) ($locker['operating_status'] ?? ''));
        }

        return '';
    }

    private static function normalizedStatus(string $status): string
    {
        return in_array($status, ['free', 'reserved', 'occupied', 'issue', 'unavailable'], true)
            ? $status
            : 'unavailable';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'free' => 'Frei',
            'reserved' => 'Reserviert',
            'occupied' => 'Belegt',
            'issue' => 'Defekt / Meldung',
            default => 'Nicht buchbar',
        };
    }

    private static function statusClass(string $status): string
    {
        return 'is-' . self::normalizedStatus($status);
    }

    private static function operatingStatusLabel(string $status): string
    {
        return match ($status) {
            'blocked' => 'Gesperrt',
            'defective' => 'Defekt',
            'maintenance' => 'Wartung',
            'out_of_service' => 'Außer Betrieb',
            default => 'Nicht buchbar',
        };
    }

    /**
     * @param list<array<string, mixed>> $lockers
     * @param callable(array<string, mixed>): string $cell
     */
    private static function render(array $lockers, string $idKey, callable $cell): string
    {
        $groups = self::groups($lockers, $idKey);
        if ($groups === []) {
            return '<p class="form-hint">Für diese Auswahl kann kein Raster dargestellt werden.</p>';
        }

        $html = '<div class="locker-grid-stack">';
        foreach ($groups as $group) {
            $location = array_values(array_filter([
                (string) $group['building_name'],
                (string) $group['floor_name'],
                (string) $group['area_name'],
            ], static fn (string $value): bool => $value !== ''));
            $html .= '<section class="locker-grid-group">'
                . '<header><div><span class="eyebrow">Schrankgruppe</span><h3>'
                . self::e((string) $group['group_code']) . '</h3></div>'
                . '<small>' . self::e(implode(' · ', $location)) . '</small></header>'
                . '<div class="locker-grid-scroll"><table class="locker-grid-table"><thead><tr><th>Fach</th>';

            foreach (array_keys($group['corpuses']) as $corpusPosition) {
                $html .= '<th>Korpus ' . str_pad((string) $corpusPosition, 2, '0', STR_PAD_LEFT) . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            for ($position = 1; $position <= (int) $group['max_locker_position']; ++$position) {
                $html .= '<tr><th>Position ' . $position . '</th>';
                foreach ($group['corpuses'] as $corpus) {
                    $locker = $corpus[$position] ?? null;
                    $html .= '<td>';
                    if (is_array($locker)) {
                        $html .= $cell($locker);
                    } else {
                        $html .= '<span class="locker-grid-empty" aria-label="kein auswählbares Fach">–</span>';
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div></section>';
        }

        return $html . '</div>';
    }

    /** @return array{group_code:string,corpus_position:int,locker_position:int} */
    private static function parseShortName(string $shortName): array
    {
        if (preg_match('/^(.+)-([0-9]+)-([0-9]+)$/', $shortName, $matches) !== 1) {
            return ['group_code' => '', 'corpus_position' => 0, 'locker_position' => 0];
        }

        return [
            'group_code' => (string) $matches[1],
            'corpus_position' => (int) $matches[2],
            'locker_position' => (int) $matches[3],
        ];
    }

    /** @param array<string, mixed> $locker */
    private static function description(array $locker): string
    {
        return implode(' · ', array_values(array_filter([
            (string) ($locker['_grid_short_name'] ?? ''),
            (string) ($locker['building_name'] ?? ''),
            (string) ($locker['floor_name'] ?? ''),
            (string) ($locker['area_name'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
