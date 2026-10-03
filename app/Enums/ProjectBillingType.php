<?php

namespace App\Enums;

enum ProjectBillingType: string
{
    /** Rozliczany godzinowo (stawka klienta × godziny). */
    case Hourly = 'hourly';

    /** Ryczałt: wartość umowy i transze; godziny tylko do kontroli rentowności. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => __('Hourly'),
            self::Fixed => __('Fixed price'),
        };
    }
}
