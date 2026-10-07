<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WorkWeek;
use App\Services\ClientPortal;
use App\Support\Hours;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Working time')] class extends Component {
    /** Tydzień ISO, np. "2026-W31". */
    #[Url]
    public string $week = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-W\d{2}$/', $this->week)) {
            $this->week = CarbonImmutable::today()->format('o-\WW');
        }
    }

    public function previousWeek(): void
    {
        $this->week = $this->monday->subWeek()->format('o-\WW');
    }

    public function nextWeek(): void
    {
        $this->week = $this->monday->addWeek()->format('o-\WW');
    }

    public function currentWeek(): void
    {
        $this->week = CarbonImmutable::today()->format('o-\WW');
    }

    #[Computed]
    public function monday(): CarbonImmutable
    {
        [$year, $week] = explode('-W', $this->week);

        return CarbonImmutable::now()->setISODate((int) $year, (int) $week)->startOfDay();
    }

    /**
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function days(): array
    {
        return array_map(fn (int $offset) => $this->monday->addDays($offset), range(0, 6));
    }

    /**
     * Godziny wszystkich osób na projektach firmy klienta w tym tygodniu (zatwierdzone i w trakcie).
     *
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return (new ClientPortal(auth()->user()))->entries()
            ->with('project')
            ->whereBetween('work_date', [$this->monday->toDateString(), $this->monday->addDays(6)->toDateString()])
            ->get();
    }

    /**
     * Wiersze siatki: projekt × dzień tygodnia (suma godzin).
     *
     * @return list<array{project: Project, days: array<int, BigDecimal>, total: BigDecimal}>
     */
    #[Computed]
    public function grid(): array
    {
        return $this->entries
            ->groupBy('project_id')
            ->map(function (Collection $entries) {
                $days = [];
                $total = BigDecimal::zero();

                foreach ($entries as $entry) {
                    $index = $entry->work_date->dayOfWeekIso - 1;
                    $days[$index] = ($days[$index] ?? BigDecimal::zero())->plus($entry->hours);
                    $total = $total->plus($entry->hours);
                }

                return ['project' => $entries->first()->project, 'days' => $days, 'total' => $total];
            })
            ->sortBy(fn (array $row) => $row['project']->number)
            ->values()
            ->all();
    }

    /**
     * @return array<int, BigDecimal>
     */
    #[Computed]
    public function dayTotals(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            $index = $entry->work_date->dayOfWeekIso - 1;
            $totals[$index] = ($totals[$index] ?? BigDecimal::zero())->plus($entry->hours);
        }

        return $totals;
    }

    /**
     * Części tego tygodnia (po miesiącach) i czy są zatwierdzone dla firmy klienta.
     *
     * @return Collection<int, WorkWeek>
     */
    #[Computed]
    public function parts(): Collection
    {
        return WorkWeek::query()
            ->where('iso_year', $this->monday->isoWeekYear())
            ->where('iso_week', $this->monday->isoWeek())
            ->with('closures')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Working time') }}</flux:heading>
        <flux:subheading>{{ auth()->user()->contractor?->name }}</flux:subheading>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:button.group>
            <flux:button icon="chevron-left" wire:click="previousWeek" :aria-label="__('Previous week')" />
            <flux:button wire:click="currentWeek">{{ __('Today') }}</flux:button>
            <flux:button icon="chevron-right" wire:click="nextWeek" :aria-label="__('Next week')" />
        </flux:button.group>

        <flux:heading size="lg">
            {{ __('CW') }} {{ $this->monday->isoWeek() }}/{{ $this->monday->isoWeekYear() }}
            <span class="font-normal text-zinc-500">· {{ $this->days[0]->format('d.m') }}–{{ $this->days[6]->format('d.m.Y') }}</span>
        </flux:heading>

        @if ($this->entries->isNotEmpty())
            @foreach ($this->parts as $part)
                @php($approved = $part->isClosedFor(auth()->user()->contractor_id))
                <flux:badge size="sm" :color="$approved ? 'green' : 'amber'">
                    {{ $part->starts_on->translatedFormat('F') }}: {{ $approved ? __('Approved') : __('In progress') }}
                </flux:badge>
            @endforeach
        @endif
    </div>

    {{-- Siatka: projekty × dni --}}
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Project') }}</flux:table.column>
            @foreach ($this->days as $day)
                <flux:table.column align="center" @class(['bg-zinc-50 dark:bg-zinc-900/40' => $day->isWeekend()])>
                    <div class="text-center leading-tight">
                        {{ $day->translatedFormat('D') }}<br><span class="text-xs font-normal text-zinc-500">{{ $day->format('d.m') }}</span>
                    </div>
                </flux:table.column>
            @endforeach
            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->grid as $row)
                <flux:table.row :key="'grid-'.$row['project']->id">
                    <flux:table.cell class="max-w-64 truncate">
                        <flux:link :href="route('portal.project', $row['project'])" wire:navigate class="font-medium">{{ $row['project']->number }}</flux:link>
                        <span class="text-zinc-500">{{ $row['project']->name }}</span>
                    </flux:table.cell>
                    @foreach ($this->days as $index => $day)
                        <flux:table.cell align="center" @class(['bg-zinc-50 dark:bg-zinc-900/40' => $day->isWeekend()])>
                            {{ isset($row['days'][$index]) ? Hours::format($row['days'][$index]) : '·' }}
                        </flux:table.cell>
                    @endforeach
                    <flux:table.cell align="end" variant="strong">{{ Hours::format($row['total']) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="9" class="text-center">{{ __('No hours in this week yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse

            @if ($this->grid !== [])
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Total') }}</flux:table.cell>
                    @foreach ($this->days as $index => $day)
                        <flux:table.cell align="center" variant="strong">{{ Hours::format($this->dayTotals[$index] ?? null) }}</flux:table.cell>
                    @endforeach
                    <flux:table.cell align="end" variant="strong">
                        {{ Hours::format(collect($this->grid)->reduce(fn ($sum, $row) => $sum->plus($row['total']), BigDecimal::zero())) }}
                    </flux:table.cell>
                </flux:table.row>
            @endif
        </flux:table.rows>
    </flux:table>
</section>
