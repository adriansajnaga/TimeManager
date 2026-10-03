<?php

namespace App\Enums;

/**
 * Język faktury: polska (klient krajowy) albo dwujęzyczna PL/EN (klient zagraniczny).
 */
enum InvoiceLanguage: string
{
    case Polish = 'pl';
    case PolishEnglish = 'pl_en';

    public function label(): string
    {
        return match ($this) {
            self::Polish => __('Polish'),
            self::PolishEnglish => __('Polish / English'),
        };
    }
}
