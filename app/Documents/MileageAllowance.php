<?php

namespace App\Documents;

use App\Enums\Language;
use App\Models\Contractor;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkWeek;
use App\Services\Mileage\MileageCalculator;
use App\Services\Mileage\MileageTrip;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kilometrówka osoby u klienta (formularz klienta, jak arkusz „Mileage allowance” Gärtnera):
 * podsumowanie tygodni (km × stawka) i trasy dzień po dniu (pn–sb).
 */
final class MileageAllowance extends WorkDocument
{
    /** @var list<MileageTrip>|null */
    private ?array $trips = null;

    /**
     * @param  Collection<int, WorkWeek>  $weeks
     */
    public function __construct(
        private readonly Contractor $client,
        private readonly Collection $weeks,
        private readonly User $user,
        private readonly ?string $kmRate = null,
    ) {}

    public function view(): string
    {
        return 'pdf.mileage';
    }

    public function language(): Language
    {
        return $this->client->document_language;
    }

    public function filename(): string
    {
        return 'Mileage_'.now()->format('Y-m-d').'.pdf';
    }

    /**
     * @return list<MileageTrip>
     */
    public function trips(): array
    {
        return $this->trips ??= app(MileageCalculator::class)->trips($this->weeks, $this->client, $this->user);
    }

    public function rate(): BigDecimal
    {
        return BigDecimal::of($this->kmRate ?? $this->client->km_rate ?? '0');
    }

    /**
     * Tygodnie ISO z dniami pn–sb i przejazdami.
     *
     * @return list<array{iso_week: int, iso_year: int, days: list<array{date: CarbonImmutable, trip: MileageTrip|null}>, km: BigDecimal, amount: BigDecimal}>
     */
    public function isoWeeks(): array
    {
        $byDate = [];

        foreach ($this->trips() as $trip) {
            $byDate[$trip->date->toDateString()] = $trip;
        }

        $weeks = [];

        foreach ($this->weeks->sortBy('starts_on') as $part) {
            $key = $part->iso_year.'-'.$part->iso_week;

            if (isset($weeks[$key])) {
                continue;
            }

            $monday = CarbonImmutable::now()->setISODate($part->iso_year, $part->iso_week)->startOfWeek()->startOfDay();
            $days = [];
            $km = BigDecimal::zero();

            foreach (range(0, 5) as $offset) {
                $date = $monday->addDays($offset);
                $trip = $byDate[$date->toDateString()] ?? null;
                $days[] = ['date' => $date, 'trip' => $trip];
                $km = $km->plus($trip->km ?? BigDecimal::zero());
            }

            $weeks[$key] = [
                'iso_week' => $part->iso_week,
                'iso_year' => $part->iso_year,
                'days' => $days,
                'km' => $km,
                'amount' => $km->multipliedBy($this->rate())->toScale(2, RoundingMode::HalfUp),
            ];
        }

        return array_values($weeks);
    }

    public function totalKm(): BigDecimal
    {
        return MileageCalculator::totalKm($this->trips());
    }

    public function data(): array
    {
        $weeks = $this->isoWeeks();
        $numbers = array_unique(array_map(fn (array $week) => $week['iso_week'], $weeks));
        $year = $weeks === [] ? now()->year : end($weeks)['iso_year'];

        return [
            ...$this->common(),
            'client' => $this->client,
            'clientLogo' => self::logoPath($this->client->logo_path),
            'user' => $this->user,
            'vehicle' => $this->client->vehicle ?? Vehicle::default(),
            'weeks' => $weeks,
            'period' => (count($numbers) > 1 ? min($numbers).'-'.max($numbers) : (string) reset($numbers)).'/'.$year,
            'rate' => $this->rate(),
            'totalKm' => $this->totalKm(),
            'totalAmount' => $this->totalKm()->multipliedBy($this->rate())->toScale(2, RoundingMode::HalfUp),
            'currency' => $this->client->currency,
            'km' => fn (BigDecimal $value) => MileageTrip::number($value),
        ];
    }
}
