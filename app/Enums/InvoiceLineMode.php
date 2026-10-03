<?php

namespace App\Enums;

/**
 * Sposób tworzenia pozycji faktury z rozliczenia.
 */
enum InvoiceLineMode: string
{
    /** Jedna pozycja: godziny + kilometrówka + materiał w jednej kwocie, opis z szablonu klienta. */
    case Single = 'single';

    /** Osobne pozycje dla godzin, kilometrówki i materiałów. */
    case Itemized = 'itemized';

    public function label(): string
    {
        return match ($this) {
            self::Single => __('Single line'),
            self::Itemized => __('Itemized lines'),
        };
    }
}
