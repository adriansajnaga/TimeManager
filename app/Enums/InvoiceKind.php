<?php

namespace App\Enums;

/**
 * Rodzaj faktury. Wszystkie poza proformą trafiają do KSeF (pole RodzajFaktury w FA(3)).
 */
enum InvoiceKind: string
{
    case Vat = 'vat';
    case Correction = 'kor';
    case Advance = 'zal';
    case Final = 'roz';
    case Proforma = 'proforma';

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('VAT invoice'),
            self::Correction => __('Correction invoice'),
            self::Advance => __('Advance invoice'),
            self::Final => __('Final invoice'),
            self::Proforma => __('Pro forma'),
        };
    }

    /** Skrót na listach: VAT, KOR, ZAL, ROZ, PRO. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Proforma => 'PRO',
            default => strtoupper($this->value),
        };
    }

    public function goesToKsef(): bool
    {
        return $this !== self::Proforma;
    }

    /**
     * Rodzaje, które można utworzyć ręcznie (korekta powstaje z wystawionej faktury).
     *
     * @return list<self>
     */
    public static function creatable(): array
    {
        return [self::Vat, self::Advance, self::Final, self::Proforma];
    }
}
