<?php

namespace App\Support;

class Months
{
    public const SHORT = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGO', 'SEP', 'OKT', 'NOV', 'DES'];

    public const LONG = [
        1 => 'Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni',
        'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba',
    ];

    /** Column headers seen in spreadsheets (English and Swahili variants). */
    public const ALIASES = [
        1 => ['JAN', 'JANUARY', 'JANUARI'],
        2 => ['FEB', 'FEBRUARY', 'FEBRUARI'],
        3 => ['MAR', 'MARCH', 'MACHI'],
        4 => ['APR', 'APRIL', 'APRILI'],
        5 => ['MAY', 'MEI'],
        6 => ['JUN', 'JUNE', 'JUNI'],
        7 => ['JUL', 'JULY', 'JULAI'],
        8 => ['AUG', 'AUGUST', 'AGO', 'AGOSTI'],
        9 => ['SEP', 'SEPT', 'SEPTEMBER', 'SEPTEMBA'],
        10 => ['OCT', 'OCTOBER', 'OKT', 'OKTOBA'],
        11 => ['NOV', 'NOVEMBER', 'NOVEMBA'],
        12 => ['DEC', 'DECEMBER', 'DES', 'DESEMBA'],
    ];

    public static function fromHeader(string $header): ?int
    {
        $h = strtoupper(trim(preg_replace('/[^A-Za-z]/', '', $header)));
        foreach (self::ALIASES as $month => $aliases) {
            if (in_array($h, $aliases, true)) {
                return $month;
            }
        }

        return null;
    }

    public static function money(int|float $amount): string
    {
        return 'TZS '.number_format((float) $amount, 0, '.', ',');
    }
}
