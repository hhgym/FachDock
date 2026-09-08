<?php

declare(strict_types=1);

namespace FachDock\Student;

final class StudentImportPreview
{
    /**
     * @param list<array{line:int, matrikelnummer:string, first_name:string, last_name:string, class_name:string, grade:int, email:?string, active:bool, category:string, messages:list<string>}> $rows
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
    ) {
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'reactivated' => 0,
            'invalid' => 0,
        ];

        foreach ($this->rows as $row) {
            if (array_key_exists($row['category'], $counts)) {
                $counts[$row['category']]++;
            }
        }

        return $counts;
    }

    public function hasInvalidRows(): bool
    {
        return ($this->counts()['invalid'] ?? 0) > 0 || $this->errors !== [];
    }
}
