<?php

namespace App\Support;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class Months
{
    public const SHORT = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGO', 'SEP', 'OKT', 'NOV', 'DES'];

    public const LONG = [
        1 => 'Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni',
        'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba',
    ];

    /** Spellings seen in spreadsheets (English and Swahili). */
    public const ALIASES = [
        1 => ['JAN', 'JANUARY', 'JANUARI'],
        2 => ['FEB', 'FEBRUARY', 'FEBRUARI', 'FEBUARI'],
        3 => ['MAR', 'MARCH', 'MACHI', 'MARCHI'],
        4 => ['APR', 'APRIL', 'APRILI'],
        5 => ['MAY', 'MEI'],
        6 => ['JUN', 'JUNE', 'JUNI'],
        7 => ['JUL', 'JULY', 'JULAI'],
        8 => ['AUG', 'AUGUST', 'AGO', 'AGOSTI', 'AGOST'],
        9 => ['SEP', 'SEPT', 'SEPTEMBER', 'SEPTEMBA'],
        10 => ['OCT', 'OCTOBER', 'OKT', 'OKTOBA', 'OCTOBA'],
        11 => ['NOV', 'NOVEMBER', 'NOVEMBA'],
        12 => ['DEC', 'DECEMBER', 'DES', 'DESEMBA', 'DISEMBA'],
    ];

    /**
     * Month from a column header or a cell: "JAN", "Januari 2026", "SEPT.",
     * "Jun-26", a real date, an Excel date serial, or 1..12 (only when
     * $allowNumbers, since headers like "No." 1 must not become January).
     */
    public static function parse(mixed $value, bool $allowNumbers = false): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return (int) $value->format('n');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $n = (float) $value;
            if ($allowNumbers && $n >= 1 && $n <= 12 && floor($n) == $n) {
                return (int) $n;
            }
            // Excel date serials (years 1990-2100).
            if ($n > 32874 && $n < 73051) {
                try {
                    return (int) ExcelDate::excelToDateTimeObject($n)->format('n');
                } catch (Throwable) {
                    return null;
                }
            }

            return null;
        }

        $text = strtoupper(trim((string) $value));
        if ($text === '') {
            return null;
        }
        // "01/2026", "2026-03", "3/26"
        if (preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{2}|\d{4})$/', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return (int) $m[1];
        }
        if (preg_match('/^\d{4}\s*[\/\-.]\s*(\d{1,2})$/', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return (int) $m[1];
        }

        $letters = preg_replace('/[^A-Z]/', '', $text);
        if (strlen($letters) < 3 || strlen($letters) > 12) {
            return null;
        }
        foreach (self::ALIASES as $month => $aliases) {
            foreach ($aliases as $alias) {
                // Exact, an abbreviation of the full name ("SEPTEM"), or the
                // full name followed by noise ("JANUARYMICHANGO" is rejected by length).
                if ($letters === $alias || (strlen($letters) >= 4 && str_starts_with($alias, $letters))) {
                    return $month;
                }
            }
        }

        return null;
    }

    /** Back-compat helper for headers. */
    public static function fromHeader(string $header): ?int
    {
        return self::parse($header);
    }

    public static function money(int|float $amount): string
    {
        return 'TZS '.number_format((float) $amount, 0, '.', ',');
    }
}
