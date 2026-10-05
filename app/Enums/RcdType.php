<?php

namespace App\Enums;

/**
 * Typ wyłącznika różnicowoprądowego i dopuszczalny zakres prądu zadziałania Ia (krotność IΔn).
 */
enum RcdType: string
{
    case AC = 'AC';
    case A = 'A';
    case F = 'F';
    case B = 'B';

    /**
     * @return array{0: float, 1: float}
     */
    public function tripRange(): array
    {
        return match ($this) {
            self::AC => [0.5, 1.0],
            self::A, self::F => [0.35, 1.4],
            self::B => [0.5, 2.0],
        };
    }
}
