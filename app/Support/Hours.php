<?php

namespace App\Support;

use App\Documents\DocumentFormat;
use App\Enums\Language;
use Brick\Math\BigDecimal;

/**
 * Godziny w interfejsie w formacie języka użytkownika ("10,75" po polsku i niemiecku).
 */
final class Hours
{
    public static function format(BigDecimal|string|null $hours): string
    {
        $language = Language::tryFrom(app()->getLocale()) ?? Language::Polish;

        return (new DocumentFormat($language))->hours($hours);
    }

    /**
     * Godziny co kwadrans do list wyboru: "00:00" … "23:45" (oraz "24:00" dla końca pracy).
     *
     * @return list<string>
     */
    public static function quarterTimes(bool $withMidnight = false): array
    {
        $times = [];

        for ($minutes = 0; $minutes < 24 * 60; $minutes += 15) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        if ($withMidnight) {
            $times[] = '24:00';
        }

        return $times;
    }
}
