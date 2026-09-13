<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use FachDock\View\LockerGridRenderer;
use PHPUnit\Framework\TestCase;

final class LockerGridRendererTest extends TestCase
{
    public function testGroupsLockersByCabinetGroupCorpusAndPosition(): void
    {
        $lockers = [
            ['locker_id' => 3, 'short_name' => 'A-02-2', 'group_code' => 'A', 'building_name' => 'Haus', 'floor_name' => '1. OG', 'area_name' => 'Nord'],
            ['locker_id' => 1, 'short_name' => 'A-01-1', 'group_code' => 'A', 'building_name' => 'Haus', 'floor_name' => '1. OG', 'area_name' => 'Nord'],
            ['locker_id' => 2, 'short_name' => 'A-02-1', 'group_code' => 'A', 'building_name' => 'Haus', 'floor_name' => '1. OG', 'area_name' => 'Nord'],
        ];

        $groups = LockerGridRenderer::groups($lockers, 'locker_id');

        self::assertCount(1, $groups);
        self::assertSame('A', $groups[0]['group_code']);
        self::assertSame(2, $groups[0]['max_locker_position']);
        self::assertSame('A-01-1', $groups[0]['corpuses'][1][1]['_grid_short_name']);
        self::assertSame('A-02-2', $groups[0]['corpuses'][2][2]['_grid_short_name']);
    }

    public function testFormGridCreatesOneSelectionFormPerLocker(): void
    {
        $html = LockerGridRenderer::formGrid(
            [
                ['locker_id' => 10, 'short_name' => 'B-01-1', 'group_code' => 'B'],
                ['locker_id' => 11, 'short_name' => 'B-01-2', 'group_code' => 'B'],
            ],
            '/reserve',
            ['_csrf' => 'token', 'student_id' => 5],
            'locker_id',
        );

        self::assertStringContainsString('Korpus 01', $html);
        self::assertStringContainsString('Position 2', $html);
        self::assertSame(2, substr_count($html, 'action="/reserve"'));
        self::assertStringContainsString('name="locker_id" value="10"', $html);
        self::assertStringContainsString('name="locker_id" value="11"', $html);
    }

    public function testStatusViewsRenderGridListIssueAndCompactListDots(): void
    {
        $lockers = [
            [
                'locker_id' => 1,
                'short_name' => 'A-01-1',
                'group_code' => 'A',
                'building_name' => 'Haus',
                'floor_name' => 'EG',
                'area_name' => 'Nord',
                'availability_status' => 'occupied',
                'occupied_student_id' => 1,
                'occupied_first_name' => 'Max',
                'occupied_last_name' => 'Muster',
                'occupied_class_name' => '8-1',
            ],
            [
                'locker_id' => 2,
                'short_name' => 'A-01-2',
                'group_code' => 'A',
                'building_name' => 'Haus',
                'floor_name' => 'EG',
                'area_name' => 'Nord',
                'availability_status' => 'reserved',
                'reserved_student_id' => 2,
                'reserved_first_name' => 'Anna',
                'reserved_last_name' => 'Beispiel',
                'reserved_class_name' => '7-1',
            ],
            [
                'locker_id' => 3,
                'short_name' => 'A-01-3',
                'group_code' => 'A',
                'building_name' => 'Haus',
                'floor_name' => 'EG',
                'area_name' => 'Nord',
                'availability_status' => 'issue',
                'open_issue_count' => 1,
            ],
        ];

        $html = LockerGridRenderer::statusViews(
            $lockers,
            static fn (array $locker, string $view, string $status): string => $status === 'reserved'
                ? '<button>' . $view . '</button>'
                : '',
        );

        self::assertStringContainsString('Rasteransicht', $html);
        self::assertStringContainsString('Listenansicht', $html);
        self::assertStringContainsString('is-reserved', $html);
        self::assertStringContainsString('is-issue', $html);
        self::assertStringContainsString('locker-status-dot', $html);
        self::assertStringContainsString('Muster, Max', $html);
        self::assertStringContainsString('8-1', $html);
        self::assertStringContainsString('1 offene Schadensmeldung', $html);
        self::assertStringContainsString('<button>list</button>', $html);
    }
}
