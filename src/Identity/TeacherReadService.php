<?php

declare(strict_types=1);

namespace FachDock\Identity;

use PDO;

final class TeacherReadService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function assignments(string $query = ''): array
    {
        $query = trim($query);
        $where = '';
        $params = [];
        if ($query !== '') {
            $where = ' AND (st.matrikelnummer LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q '
                . "OR CONCAT(st.first_name, ' ', st.last_name) LIKE :q OR st.class_name LIKE :q OR l.short_name LIKE :q)";
            $params['q'] = '%' . $query . '%';
        }
        $statement = $this->pdo->prepare(
            'SELECT st.id AS student_id, st.matrikelnummer, st.first_name, st.last_name, st.class_name, st.grade, '
            . 'sy.label AS school_year, b.status AS booking_status, l.short_name AS locker_name, '
            . 'bu.name AS building_name, f.name AS floor_name, a.name AS area_name '
            . 'FROM students st '
            . "LEFT JOIN bookings b ON b.student_id = st.id AND b.status IN ('active','payment_due','exemption_review','manual_review') "
            . 'LEFT JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id '
            . 'LEFT JOIN corpuses c ON c.id = l.corpus_id '
            . 'LEFT JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'LEFT JOIN areas a ON a.id = cg.area_id '
            . 'LEFT JOIN floors f ON f.id = a.floor_id '
            . 'LEFT JOIN buildings bu ON bu.id = f.building_id '
            . 'WHERE st.active = 1' . $where
            . ' ORDER BY st.class_name, st.last_name, st.first_name LIMIT 100'
        );
        $statement->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
