<?php

namespace App\Services\Settlements;

use App\Models\Contractor;
use App\Models\MaterialEntry;
use App\Models\WorkWeek;
use App\Services\Mileage\MileageTrip;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Wyliczenie rozliczenia przed utworzeniem faktury.
 */
final class SettlementPreview
{
    /**
     * @param  Collection<int, WorkWeek>  $weeks
     * @param  list<string>  $labels  etykiety projektów na fakturze
     * @param  list<MileageTrip>  $trips
     * @param  Collection<int, MaterialEntry>  $materials
     */
    public function __construct(
        public readonly Contractor $contractor,
        public readonly Collection $weeks,
        public readonly BigDecimal $hours,
        public readonly BigDecimal $hourlyRate,
        public readonly BigDecimal $km,
        public readonly BigDecimal $kmRate,
        public readonly ?CarbonImmutable $periodFrom,
        public readonly ?CarbonImmutable $periodTo,
        public readonly array $labels,
        public readonly array $trips,
        public readonly Collection $materials,
    ) {}

    public function hoursAmount(): BigDecimal
    {
        return $this->hours->multipliedBy($this->hourlyRate)->toScale(2, RoundingMode::HalfUp);
    }

    public function kmAmount(): BigDecimal
    {
        return $this->km->multipliedBy($this->kmRate)->toScale(2, RoundingMode::HalfUp);
    }

    public function total(): BigDecimal
    {
        return $this->hoursAmount()->plus($this->kmAmount());
    }

    /**
     * Dni kilometrówki bez wpisanych km.
     *
     * @return list<MileageTrip>
     */
    public function tripsWithoutKm(): array
    {
        return array_values(array_filter($this->trips, fn (MileageTrip $trip) => $trip->needsKm()));
    }

    /**
     * Braki uniemożliwiające rozliczenie.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->weeks->isEmpty()) {
            $problems[] = __('Choose at least one closed week.');
        }

        if ($this->hours->isZero()) {
            $problems[] = __('The chosen weeks have no hours of this client\'s hourly projects.');
        }

        if ($this->hourlyRate->isZero()) {
            $problems[] = __('Set the hourly rate in the contractor card.');
        }

        if ($this->tripsWithoutKm() !== []) {
            $problems[] = __('Enter the mileage kilometres for: :days.', ['days' => implode(', ', array_map(fn (MileageTrip $trip) => $trip->date->format('d.m'), $this->tripsWithoutKm()))]);
        }

        if (! $this->km->isZero() && $this->kmRate->isZero()) {
            $problems[] = __('Set the rate per km in the contractor card.');
        }

        return $problems;
    }
}
