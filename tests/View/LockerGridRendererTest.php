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
}
