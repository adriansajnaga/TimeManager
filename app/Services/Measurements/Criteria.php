<?php

namespace App\Services\Measurements;

use App\Enums\ProtectionType;
use App\Enums\RcdType;

/**
 * Wyliczenia i kryteria oceny pomiarów instalacji (PN-HD 60364-4-41, PN-HD 60364-6).
 * Wartości liczone tu, a nie przepisywane ręcznie — jak w protokole: Ia = k·In, Za = Uo/Ia, Ik = Uo/Zs.
 */
final class Criteria
{
    /**
     * Prąd Ia [A] wkładek gG/gL dla czasu wyłączenia 0,4 s i 5 s (wartości typowe z charakterystyk;
     * przy innej wkładce wpisz Ia ręcznie w obwodzie).
     *
     * @var array<string, array<int, int|float>>
     */
    public const GG_TRIP_CURRENTS = [
        'ta04' => [2 => 15, 4 => 30, 6 => 46, 10 => 77, 16 => 110, 20 => 135, 25 => 180, 32 => 260, 40 => 327, 50 => 420, 63 => 537],
        'ta5' => [2 => 9.2, 4 => 18.5, 6 => 28, 10 => 48, 16 => 65, 20 => 85, 25 => 110, 32 => 150, 40 => 190, 50 => 250, 63 => 320, 80 => 425, 100 => 580, 125 => 715, 160 => 950, 200 => 1250, 250 => 1650, 315 => 2200, 400 => 2840, 500 => 3800, 630 => 5100],
    ];

    /** Czas zadziałania RCD przy 1×IΔn [ms]: bezzwłoczny ≤ 300, selektywny (S) 130–500. */
    public const RCD_TIME_GENERAL = 300;

    public const RCD_TIME_SELECTIVE = [130, 500];

    /**
     * Ia [A] dla zabezpieczenia; null, gdy nie da się wyliczyć (brak In albo wkładki spoza tabeli).
     * Wkładki gG w obwodach rozdzielczych (WLZ) i powyżej 63 A: czas wyłączenia 5 s (PN-HD 60364-4-41, sieć TN).
     */
    public static function tripCurrent(?ProtectionType $type, ?float $ratedCurrent, float $disconnectionTime, ?float $override = null, bool $distribution = false): ?float
    {
        if ($override !== null && $override > 0) {
            return $override;
        }

        if ($type === null || $ratedCurrent === null || $ratedCurrent <= 0) {
            return null;
        }

        if (($multiplier = $type->multiplier()) !== null) {
            return $multiplier * $ratedCurrent;
        }

        $long = $disconnectionTime >= 5 || $distribution || $ratedCurrent > 63;
        $table = self::GG_TRIP_CURRENTS[$long ? 'ta5' : 'ta04'];

        return $table[(int) round($ratedCurrent)] ?? null;
    }

    /** Za = Uo / Ia [Ω]. */
    public static function allowedImpedance(int $phaseVoltage, ?float $tripCurrent): ?float
    {
        return $tripCurrent !== null && $tripCurrent > 0 ? $phaseVoltage / $tripCurrent : null;
    }

    /** Ik = Uo / Zs [A]. */
    public static function shortCircuitCurrent(int $phaseVoltage, ?float $impedance): ?float
    {
        return $impedance !== null && $impedance > 0 ? $phaseVoltage / $impedance : null;
    }

    /**
     * Ocena pętli: Zs ≤ Za; null — brak pomiaru albo brak Za.
     */
    public static function loopPasses(?float $impedance, ?float $allowed): ?bool
    {
        return $impedance === null || $allowed === null ? null : $impedance <= $allowed;
    }

    /**
     * Wymagana rezystancja izolacji Ra [MΩ] od napięcia probierczego (250 V — SELV/PELV: 0,5; 500 i 1000 V: 1,0).
     */
    public static function requiredInsulation(int $testVoltage): float
    {
        return $testVoltage <= 250 ? 0.5 : 1.0;
    }

    /**
     * Odczyt rezystancji izolacji: „>30”, „> 1000”, „1,5” → wartość liczbowa; null — puste lub nieczytelne.
     */
    public static function insulationValue(?string $reading): ?float
    {
        $clean = str_replace([' ', ','], ['', '.'], trim((string) $reading));
        $clean = ltrim($clean, '>≥');

        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function insulationPasses(?string $reading, float $required): ?bool
    {
        $value = self::insulationValue($reading);

        return $value === null ? null : $value >= $required;
    }

    /**
     * Ocena RCD: czas przy 1×IΔn, prąd zadziałania w zakresie typu, napięcie dotyku ≤ UL i przycisk TEST.
     * Zwraca listę niespełnionych warunków (pusta = pozytywna) albo null, gdy brak pomiarów.
     *
     * @return list<string>|null
     */
    public static function rcdFailures(RcdType $type, bool $selective, int $ratedResidual, ?float $tripTime, ?float $tripCurrent, ?float $contactVoltage, int $touchVoltage, bool $testButton): ?array
    {
        if ($tripTime === null && $tripCurrent === null) {
            return null;
        }

        $failures = [];

        if ($tripTime !== null) {
            [$min, $max] = $selective ? self::RCD_TIME_SELECTIVE : [0, self::RCD_TIME_GENERAL];

            if ($tripTime < $min || $tripTime > $max) {
                $failures[] = 'time';
            }
        }

        if ($tripCurrent !== null) {
            [$low, $high] = $type->tripRange();

            if ($tripCurrent < $low * $ratedResidual || $tripCurrent > $high * $ratedResidual) {
                $failures[] = 'current';
            }
        }

        if ($contactVoltage !== null && $contactVoltage > $touchVoltage) {
            $failures[] = 'contact_voltage';
        }

        if (! $testButton) {
            $failures[] = 'test_button';
        }

        return $failures;
    }

    /** Uziemienie: RE · Kp ≤ Ra. */
    public static function earthingPasses(?float $resistance, float $correction, float $limit): ?bool
    {
        return $resistance === null ? null : $resistance * $correction <= $limit;
    }

    /** Ciągłość: R ≤ wartość dopuszczalna (bez wartości dopuszczalnej — wystarczy pomiar). */
    public static function continuityPasses(?float $resistance, ?float $limit): ?bool
    {
        if ($resistance === null) {
            return null;
        }

        return $limit === null || $resistance <= $limit;
    }
}
