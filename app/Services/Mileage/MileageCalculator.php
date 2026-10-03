<?php

namespace App\Services\Mileage;

use App\Models\Contractor;
use App\Models\MileageDay;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kilometrówka z wpisów godzin oznaczonych „licz kilometry”.
 * Jeden projekt danego dnia: km = 2 × odległość w jedną stronę; kilka projektów: km wpisane ręcznie.
 */
final class MileageCalculator
{
    /**
     * @param  Collection<int, WorkWeek>  $weeks
     * @return list<MileageTrip>
     */
    public function trips(Collection $weeks, ?Contractor $client = null, ?User $user = null): array
    {
        $entries = TimeEntry::query()
            ->with(['project.contractor', 'user'])
            ->where('count_mileage', true)
            ->whereIn('work_week_id', $weeks->pluck('id'))
            ->when($client !== null, fn ($query) => $query->whereHas('project', fn ($projects) => $projects->where('contractor_id', $client?->id)))
            ->when($user !== null, fn ($query) => $query->where('user_id', $user?->id))
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $overrides = MileageDay::query()
            ->whereIn('user_id', $entries->pluck('user_id')->unique())
            ->whereBetween('trip_date', [$entries->min('work_date')?->toDateString(), $entries->max('work_date')?->toDateString()])
            ->get()
            ->keyBy(fn (MileageDay $day) => $day->user_id.'-'.$day->contractor_id.'-'.$day->trip_date->toDateString());

        $trips = [];

        foreach ($entries->groupBy(fn (TimeEntry $entry) => $entry->user_id.'-'.$entry->project->contractor_id.'-'.$entry->work_date->toDateString()) as $key => $day) {
            /** @var TimeEntry $first */
            $first = $day->first();
            $projects = array_values($day->map(fn (TimeEntry $entry) => $entry->project)->unique('id')->all());
            $override = $overrides->get((string) $key);

            $autoKm = count($projects) === 1 && $projects[0]->km_one_way !== null
                ? BigDecimal::of($projects[0]->km_one_way)->multipliedBy(2)
                : null;

            $trips[] = new MileageTrip(
                user: $first->user,
                client: $first->project->contractor,
                date: $first->work_date,
                projects: $projects,
                route: $override->route ?? self::route($first->project->contractor, $projects),
                km: $override?->km !== null ? BigDecimal::of($override->km) : $autoKm,
                manualKm: $override?->km !== null,
                manualRoute: $override?->route !== null,
            );
        }

        return $trips;
    }

    /**
     * Trasa „baza -> miejsce -> … -> baza”, np. „Zum Brook 24113 Kiel -> Jagel -> Zum Brook 24113 Kiel”.
     *
     * @param  list<Project>  $projects
     */
    public static function route(Contractor $client, array $projects): string
    {
        $base = trim((string) $client->base_address);
        $stops = array_values(array_unique(array_map(fn (Project $project) => $project->routeStop(), $projects)));

        return implode(' -> ', array_values(array_filter([$base, ...$stops, $base], fn (string $stop) => $stop !== '')));
    }

    /**
     * @param  list<MileageTrip>  $trips
     */
    public static function totalKm(array $trips): BigDecimal
    {
        return array_reduce($trips, fn (BigDecimal $sum, MileageTrip $trip) => $sum->plus($trip->km ?? BigDecimal::zero()), BigDecimal::zero());
    }

    /**
     * Zapis poprawki dnia (puste km i trasa = usuń poprawkę).
     */
    public function saveDay(User $user, Contractor $client, CarbonImmutable $date, ?string $km, ?string $route): void
    {
        $day = MileageDay::query()
            ->where('user_id', $user->id)
            ->where('contractor_id', $client->id)
            ->where('trip_date', $date->toDateString())
            ->first();

        if ($km === null && $route === null) {
            $day?->delete();

            return;
        }

        ($day ?? new MileageDay(['user_id' => $user->id, 'contractor_id' => $client->id, 'trip_date' => $date]))
            ->fill(['km' => $km, 'route' => $route])
            ->save();
    }
}
