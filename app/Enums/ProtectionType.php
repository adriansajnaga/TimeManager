<?php

namespace App\Enums;

/**
 * Zabezpieczenie nadprądowe obwodu: wyłącznik B/C/D albo wkładka topikowa gG (gL/gG, także NH00).
 */
enum ProtectionType: string
{
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case GG = 'gG';

    public function label(): string
    {
        return $this->value;
    }

    /** Krotność In zapewniająca wyłączenie (wyłączniki); wkładki — z tabeli czasowo-prądowej. */
    public function multiplier(): ?int
    {
        return match ($this) {
            self::B => 5,
            self::C => 10,
            self::D => 20,
            self::GG => null,
        };
    }
}
