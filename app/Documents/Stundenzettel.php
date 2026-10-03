<?php

namespace App\Documents;

use App\Enums\Language;
use App\Models\Contractor;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Stundenzettel: tabela godzin dla każdej części tygodnia i Zusammenfassung
 * (NAME / STUNDEN / STUNDENPREIS / GESAMT) — jak w starej aplikacji.
 */
final class Stundenzettel extends WorkDocument
{
    /**
     * @param  Collection<int, WorkWeek>  $weeks
     */
    public function __construct(
        private readonly Contractor $client,
        private readonly Collection $weeks,
        private readonly ?string $hourlyRate = null,
    ) {}

    public function view(): string
    {
        return 'pdf.stundenzettel';
    }

    public function language(): Language
    {
        return $this->client->document_language;
    }

    public function filename(): string
    {
        return 'Stundenzettel_'.now()->format('Y-m-d').'.pdf';
    }

    /**
     * Stawka godzinowa: przekazana (np. z rozliczenia) albo aktualna stawka klienta.
     */
    public function rate(): BigDecimal
    {
        return BigDecimal::of($this->hourlyRate ?? $this->client->hourly_rate ?? '0');
    }

    /**
     * Części tygodni od najnowszego tygodnia; w obrębie tygodnia — miesiącami (jak w starej aplikacji).
     *
     * @return list<array{week: WorkWeek, rows: list<WeekHours>}>
     */
    public function sections(): array
    {
        $entries = TimeEntry::query()
            ->with('user')
            ->whereIn('work_week_id', $this->weeks->pluck('id'))
            ->whereHas('project', fn ($query) => $query->where('contractor_id', $this->client->id))
            ->get()
            ->groupBy('work_week_id');

        return array_values($this->weeks
            ->sortBy([['iso_year', 'desc'], ['iso_week', 'desc'], ['year', 'asc'], ['month', 'asc']])
            ->filter(fn (WorkWeek $week) => $entries->has($week->id))
            ->map(fn (WorkWeek $week) => [
                'week' => $week,
                'rows' => WeekHours::perUser($entries->get($week->id)),
            ])
            ->all());
    }

    /**
     * Podsumowanie na osobę: godziny × stawka.
     *
     * @return list<array{user: User, hours: BigDecimal, rate: BigDecimal, amount: BigDecimal}>
     */
    public function summary(): array
    {
        $totals = [];

        foreach ($this->sections() as $section) {
            foreach ($section['rows'] as $row) {
                $current = $totals[$row->user->id] ?? ['user' => $row->user, 'hours' => BigDecimal::zero()];
                $current['hours'] = $current['hours']->plus($row->total);
                $totals[$row->user->id] = $current;
            }
        }

        $rate = $this->rate();

        return array_values(collect($totals)
            ->sortBy(fn (array $total) => $total['user']->name)
            ->map(fn (array $total) => [
                ...$total,
                'rate' => $rate,
                'amount' => $total['hours']->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp),
            ])
            ->all());
    }

    public function data(): array
    {
        $common = $this->common();

        return [
            ...$common,
            'client' => $this->client,
            'clientTaxLine' => self::taxLine($this->client, $common['t']),
            'sections' => $this->sections(),
            'summary' => $this->summary(),
            'currency' => $this->client->currency,
        ];
    }
}
