<?php

namespace App\Enums;

/**
 * Języki interfejsu oraz dokumentów roboczych (Montageauftrag, Stundenzettel, kilometrówka, e-mail).
 */
enum Language: string
{
    case Polish = 'pl';
    case English = 'en';
    case German = 'de';

    public function label(): string
    {
        return match ($this) {
            self::Polish => 'Polski',
            self::English => 'English',
            self::German => 'Deutsch',
        };
    }
}
