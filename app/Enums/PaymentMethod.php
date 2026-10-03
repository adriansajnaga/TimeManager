<?php

namespace App\Enums;

/**
 * Forma płatności z kodem pola FormaPlatnosci w FA(3).
 */
enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Bank transfer'),
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::Mobile => __('Mobile payment'),
        };
    }

    public function ksefCode(): string
    {
        return match ($this) {
            self::Cash => '1',
            self::Card => '2',
            self::Transfer => '6',
            self::Mobile => '7',
        };
    }
}
