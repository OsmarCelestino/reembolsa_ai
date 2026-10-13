<?php

namespace App\Support;

/**
 * Gera um PDF simples (uma página, texto em Helvetica) sem dependências.
 * Usado só para criar os comprovantes fictícios de demonstração.
 */
final class SimplePdf
{
    /** @param list<string> $lines */
    public static function fromLines(array $lines, int $fontSize = 11): string
    {
        $height = 842; // A4
        $y = $height - 60;
        $stream = "BT\n/F1 {$fontSize} Tf\n";

        foreach ($lines as $line) {
            $stream .= sprintf("1 0 0 1 50 %d Tm (%s) Tj\n", $y, self::escape($line));
            $y -= (int) ($fontSize * 1.6);
        }

        $stream .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 {$height}] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function escape(string $text): string
    {
        $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
