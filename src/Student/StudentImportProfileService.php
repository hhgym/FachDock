<?php

declare(strict_types=1);

namespace FachDock\Student;

use JsonException;
use PDO;
use RuntimeException;

final class StudentImportProfileService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>}> */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, delimiter_char, enclosure_char, encoding, column_mapping '
            . 'FROM student_import_profiles WHERE active = 1 ORDER BY name'
        );
        if ($statement === false) {
            return [];
        }

        $profiles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $profiles[] = $this->hydrate($row);
        }

        return $profiles;
    }

    /** @return array{id:int,name:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>} */
    public function find(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, delimiter_char, enclosure_char, encoding, column_mapping '
            . 'FROM student_import_profiles WHERE id = :id AND active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Das ausgewählte Importprofil existiert nicht.');
        }

        return $this->hydrate($row);
    }

    /** @param array<string, string> $mapping @throws JsonException */
    public function create(
        string $name,
        string $delimiter,
        string $enclosure,
        string $encoding,
        array $mapping,
    ): int {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Das Importprofil benötigt einen Namen.');
        }
        if (!in_array($delimiter, [';', ',', "\t"], true)) {
            throw new RuntimeException('Ungültiges Trennzeichen für das Importprofil.');
        }
        if ($enclosure !== '"') {
            throw new RuntimeException('Aktuell wird als Textbegrenzungszeichen nur das doppelte Anführungszeichen unterstützt.');
        }
        if (!in_array($encoding, ['UTF-8', 'WINDOWS-1252', 'ISO-8859-1'], true)) {
            throw new RuntimeException('Ungültige Zeichenkodierung für das Importprofil.');
        }

        $clean = [];
        foreach ($mapping as $field => $header) {
            $header = trim($header);
            if ($header !== '') {
                $clean[$field] = $header;
            }
        }
        foreach (['matrikelnummer', 'first_name', 'last_name', 'class_name', 'active'] as $required) {
            if (!isset($clean[$required])) {
                throw new RuntimeException('Im Importprofil fehlt die Zuordnung für: ' . $required);
            }
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO student_import_profiles '
            . '(name, delimiter_char, enclosure_char, encoding, has_header, column_mapping, active, created_at, updated_at) '
            . 'VALUES (:name, :delimiter, :enclosure, :encoding, 1, :mapping, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'name' => $name,
            'delimiter' => $delimiter,
            'enclosure' => $enclosure,
            'encoding' => $encoding,
            'mapping' => json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Das Importprofil konnte nicht angelegt werden.');
        }

        return $id;
    }

    public function markUsed(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE student_import_profiles SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int,name:string,delimiter:string,enclosure:string,encoding:string,mapping:array<string,string>}
     */
    private function hydrate(array $row): array
    {
        $mapping = json_decode((string) ($row['column_mapping'] ?? '{}'), true);
        if (!is_array($mapping)) {
            $mapping = [];
        }

        $clean = [];
        foreach ($mapping as $field => $header) {
            if (is_string($field) && is_string($header)) {
                $clean[$field] = $header;
            }
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'delimiter' => (string) $row['delimiter_char'],
            'enclosure' => (string) $row['enclosure_char'],
            'encoding' => (string) $row['encoding'],
            'mapping' => $clean,
        ];
    }
}
