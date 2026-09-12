<?php

declare(strict_types=1);

namespace FachDock\Booking;

final class BookingReceiptPdf
{
    /** @param list<string> $lines */
    public function render(string $title, array $lines): string
    {
        $content = "BT\n/F1 18 Tf\n50 790 Td\n" . $this->text($title) . " Tj\n";
        $content .= "0 -32 Td\n/F1 10 Tf\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -17 Td\n";
            }
            $content .= $this->text($line) . " Tj\n";
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private function text(string $value): string
    {
        $encoded = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        $encoded = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);

        return '(' . $encoded . ')';
    }
}
