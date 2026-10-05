<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Daty w protokołach: „15 lutego 2025 r.” i termin badań „luty 2030”.
 */
final class PolishDate
{
    private const GENITIVE = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

    private const NOMINATIVE = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public static function long(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->day.' '.self::GENITIVE[$date->month - 1].' '.$date->year.' r.';
    }

    public static function monthYear(?CarbonInterface $date): string
    {
        return $date === null ? '—' : self::NOMINATIVE[$date->month - 1].' '.$date->year;
    }
}
