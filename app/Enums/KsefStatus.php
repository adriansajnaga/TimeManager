<?php

namespace App\Enums;

/**
 * Stan faktury w KSeF: wysłana i czeka na weryfikację albo przyjęta (ma numer KSeF).
 */
enum KsefStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for KSeF'),
            self::Accepted => __('In KSeF'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Accepted => 'green',
        };
    }
}
