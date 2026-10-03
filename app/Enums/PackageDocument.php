<?php

namespace App\Enums;

/**
 * Dokumenty, które mogą trafić do pakietu PDF wysyłanego klientowi.
 */
enum PackageDocument: string
{
    case Invoice = 'invoice';
    case Stundenzettel = 'stundenzettel';
    case Mileage = 'mileage';
    case Montageauftrag = 'montageauftrag';
    case Stundennachweis = 'stundennachweis';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::Stundenzettel => __('Timesheet summary (Stundenzettel)'),
            self::Mileage => __('Mileage allowance'),
            self::Montageauftrag => __('Weekly reports (Montageauftrag)'),
            self::Stundennachweis => __('Weekly time record (Stundennachweis)'),
        };
    }

    /**
     * Domyślna kolejność pakietu: Faktura → Stundenzettel → Kilometrówka → Montageaufträge.
     *
     * @return list<string>
     */
    public static function defaultOrder(): array
    {
        return [
            self::Invoice->value,
            self::Stundenzettel->value,
            self::Mileage->value,
            self::Montageauftrag->value,
        ];
    }
}
