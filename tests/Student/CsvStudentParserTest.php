<?php

declare(strict_types=1);

namespace FachDock\Tests\Student;

use FachDock\Student\CsvStudentParser;
use PHPUnit\Framework\TestCase;

final class CsvStudentParserTest extends TestCase
{
    public function testParserDerivesGradeAndDetectsDuplicateMatrikelnummer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fachdock-csv-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            "Matrikelnummer;Vorname;Nachname;Klasse;Aktiv;Email\n"
            . "1001;Anna;Muster;7-1;ja;anna@example.org\n"
            . "1001;Ben;Beispiel;8-2;1;ben@example.org\n"
        );

        try {
            $rows = (new CsvStudentParser())->parse($path);
        } finally {
            @unlink($path);
        }

        self::assertCount(2, $rows);
        self::assertSame(7, $rows[0]['grade']);
        self::assertSame('pending', $rows[0]['category']);
        self::assertSame('invalid', $rows[1]['category']);
        self::assertContains('Matrikelnummer kommt in der CSV mehrfach vor.', $rows[1]['messages']);
    }

    public function testInvalidEmailAndStatusAreRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fachdock-csv-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            "Matrikelnummer;Vorname;Nachname;Klasse;Aktiv;Email\n"
            . "1002;Clara;Test;9-1;vielleicht;nicht-gueltig\n"
        );

        try {
            $rows = (new CsvStudentParser())->parse($path);
        } finally {
            @unlink($path);
        }

        self::assertSame('invalid', $rows[0]['category']);
        self::assertContains('E-Mail-Adresse ist ungültig.', $rows[0]['messages']);
        self::assertContains('Aktiv-Status ist ungültig.', $rows[0]['messages']);
    }

    public function testCustomColumnMappingSupportsSourceSpecificHeaders(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fachdock-csv-');
        self::assertIsString($path);
        file_put_contents(
            $path,
            "ID;Given;Family;Group;Enabled;Year;Contact\n"
            . "4711;Dora;Demo;10-3;true;10;dora@example.org\n"
        );

        try {
            $rows = (new CsvStudentParser())->parse($path, ';', '"', 'UTF-8', [
                'matrikelnummer' => 'ID',
                'first_name' => 'Given',
                'last_name' => 'Family',
                'class_name' => 'Group',
                'active' => 'Enabled',
                'grade' => 'Year',
                'email' => 'Contact',
            ]);
        } finally {
            @unlink($path);
        }

        self::assertCount(1, $rows);
        self::assertSame('4711', $rows[0]['matrikelnummer']);
        self::assertSame('Dora', $rows[0]['first_name']);
        self::assertSame('Demo', $rows[0]['last_name']);
        self::assertSame('10-3', $rows[0]['class_name']);
        self::assertSame(10, $rows[0]['grade']);
        self::assertSame('dora@example.org', $rows[0]['email']);
        self::assertTrue($rows[0]['active']);
        self::assertSame('pending', $rows[0]['category']);
    }
}
