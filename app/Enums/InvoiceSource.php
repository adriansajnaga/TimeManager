<?php

namespace App\Enums;

enum InvoiceSource: string
{
    /** Wystawiona w aplikacji. */
    case App = 'app';

    /** Pobrana z KSeF (wystawiona gdzie indziej albo zakup). */
    case Ksef = 'ksef';

    /** Zakup wpisany ręcznie (np. zagraniczny dostawca spoza KSeF). */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::App => __('Issued here'),
            self::Ksef => __('From KSeF'),
            self::Manual => __('Entered manually'),
        };
    }
}
