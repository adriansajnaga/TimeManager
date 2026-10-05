<?php

namespace App\Support;

/**
 * Wartości wpisywane na budowie: przecinek albo kropka, puste = brak pomiaru.
 */
final class MeasurementInput
{
    /** „1,36” → „1.36”; puste albo nieliczbowe → null. */
    public static function decimal(mixed $value): ?string
    {
        $clean = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($clean) ? $clean : null;
    }

    /** Odczyt izolacji zostaje jak wpisany (np. „>30”), tylko bez spacji i z przecinkiem dziesiętnym. */
    public static function reading(mixed $value): ?string
    {
        $clean = str_replace(' ', '', trim((string) $value));

        return $clean === '' ? null : mb_substr(str_replace('.', ',', $clean), 0, 20);
    }

    /** Liczba do pola formularza: 1.360 → „1,36”. */
    public static function show(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $text = (string) $value;

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return str_replace('.', ',', $text);
    }

    /** Wynik do wyświetlenia z dokładnością: 2,875 → „2,88” (dla 2 miejsc). */
    public static function format(?float $value, int $decimals = 2): string
    {
        return $value === null ? '—' : number_format($value, $decimals, ',', ' ');
    }
}
