<?php

declare(strict_types=1);

namespace FachDock\Student;

use RuntimeException;

final class CsvStudentParser
{
    /**
     * @param array<string, string> $mapping logical field => CSV header
     * @return list<array{line:int, matrikelnummer:string, first_name:string, last_name:string, class_name:string, grade:int, email:?string, active:bool, category:string, messages:list<string>}>
     */
    public function parse(
        string $path,
        string $delimiter = ';',
        string $enclosure = '"',
        string $encoding = 'UTF-8',
        array $mapping = [],
    ): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Die CSV-Datei konnte nicht geöffnet werden.');
        }

        try {
            $header = fgetcsv($handle, 0, $delimiter, $enclosure, '');
            if (!is_array($header)) {
                throw new RuntimeException('Die CSV-Datei enthält keine Kopfzeile.');
            }

            $header = array_map(fn (mixed $value): string => $this->convert((string) $value, $encoding), $header);
            $columns = $this->resolveColumns($header, $mapping);
            $rows = [];
            $seen = [];
            $line = 1;

            while (($data = fgetcsv($handle, 0, $delimiter, $enclosure, '')) !== false) {
                $line++;
                if ($this->isEmptyRow($data)) {
                    continue;
                }

                $values = array_map(fn (mixed $value): string => trim($this->convert((string) $value, $encoding)), $data);
                $row = $this->row($values, $columns, $line);
                if ($row['matrikelnummer'] !== '') {
                    if (isset($seen[$row['matrikelnummer']])) {
                        $row['category'] = 'invalid';
                        $row['messages'][] = 'Matrikelnummer kommt in der CSV mehrfach vor.';
                    }
                    $seen[$row['matrikelnummer']] = true;
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $header @param array<string, string> $mapping @return array<string, int> */
    private function resolveColumns(array $header, array $mapping): array
    {
        $normalized = [];
        foreach ($header as $index => $name) {
            $normalized[$this->normalizeHeader($name)] = $index;
        }

        $aliases = [
            'matrikelnummer' => ['matrikelnummer', 'matrikel', 'student_id', 'schueler_id'],
            'first_name' => ['vorname', 'first_name', 'firstname'],
            'last_name' => ['nachname', 'last_name', 'lastname'],
            'class_name' => ['klasse', 'class', 'class_name'],
            'grade' => ['stufe', 'jahrgang', 'grade'],
            'email' => ['email', 'e_mail', 'mail'],
            'active' => ['aktiv', 'active', 'status'],
        ];

        $columns = [];
        foreach ($aliases as $field => $candidates) {
            if (isset($mapping[$field])) {
                $candidates = [$this->normalizeHeader($mapping[$field])];
            }
            foreach ($candidates as $candidate) {
                $candidate = $this->normalizeHeader($candidate);
                if (array_key_exists($candidate, $normalized)) {
                    $columns[$field] = $normalized[$candidate];
                    break;
                }
            }
        }

        foreach (['matrikelnummer', 'first_name', 'last_name', 'class_name', 'active'] as $required) {
            if (!array_key_exists($required, $columns)) {
                throw new RuntimeException('Pflichtspalte fehlt oder ist nicht zugeordnet: ' . $required);
            }
        }

        return $columns;
    }

    /**
     * @param list<string> $values
     * @param array<string, int> $columns
     * @return array{line:int, matrikelnummer:string, first_name:string, last_name:string, class_name:string, grade:int, email:?string, active:bool, category:string, messages:list<string>}
     */
    private function row(array $values, array $columns, int $line): array
    {
        $matrikelnummer = $this->value($values, $columns, 'matrikelnummer');
        $firstName = $this->value($values, $columns, 'first_name');
        $lastName = $this->value($values, $columns, 'last_name');
        $className = $this->value($values, $columns, 'class_name');
        $gradeText = $this->value($values, $columns, 'grade');
        $emailText = $this->value($values, $columns, 'email');
        $activeText = $this->value($values, $columns, 'active');
        $messages = [];

        if ($matrikelnummer === '') {
            $messages[] = 'Matrikelnummer fehlt.';
        }
        if ($firstName === '') {
            $messages[] = 'Vorname fehlt.';
        }
        if ($lastName === '') {
            $messages[] = 'Nachname fehlt.';
        }
        if ($className === '') {
            $messages[] = 'Klasse fehlt.';
        }

        $grade = $this->grade($gradeText, $className);
        if ($grade < 1 || $grade > 13) {
            $messages[] = 'Klassenstufe konnte nicht gültig bestimmt werden.';
        }

        $email = $emailText !== '' ? mb_strtolower($emailText) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $messages[] = 'E-Mail-Adresse ist ungültig.';
        }

        $active = $this->active($activeText);
        if ($active === null) {
            $messages[] = 'Aktiv-Status ist ungültig.';
            $active = false;
        }

        return [
            'line' => $line,
            'matrikelnummer' => $matrikelnummer,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'class_name' => $className,
            'grade' => $grade,
            'email' => $email,
            'active' => $active,
            'category' => $messages === [] ? 'pending' : 'invalid',
            'messages' => $messages,
        ];
    }

    private function grade(string $gradeText, string $className): int
    {
        if ($gradeText !== '' && ctype_digit($gradeText)) {
            return (int) $gradeText;
        }
        if (preg_match('/^\s*(\d{1,2})(?:\D|$)/u', $className, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function active(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            '1', 'true', 'ja', 'yes', 'y', 'aktiv', 'active' => true,
            '0', 'false', 'nein', 'no', 'n', 'inaktiv', 'inactive' => false,
            default => null,
        };
    }

    /** @param list<string> $values @param array<string, int> $columns */
    private function value(array $values, array $columns, string $field): string
    {
        if (!isset($columns[$field])) {
            return '';
        }

        return $values[$columns[$field]] ?? '';
    }

    /** @param array<int, string|null> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $value);

        return preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    }

    private function convert(string $value, string $encoding): string
    {
        $encoding = strtoupper(trim($encoding));
        if ($encoding === '' || $encoding === 'UTF-8' || $encoding === 'UTF8') {
            return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        }

        return mb_convert_encoding($value, 'UTF-8', $encoding);
    }
}
