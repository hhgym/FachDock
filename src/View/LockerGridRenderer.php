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
