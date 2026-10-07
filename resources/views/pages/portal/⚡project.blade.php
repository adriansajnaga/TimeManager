<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WorkWeek;
use App\Services\ClientPortal;
use App\Support\Hours;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        (new ClientPortal(auth()->user()))->authorize($project);
        $this->project = $project;
    }

    /**
     * Wpisy projektu (bez stawek) — zatwierdzone i w trakcie, najnowsze najpierw.
     *
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return (new ClientPortal(auth()->user()))->entries()
            ->where('project_id', $this->project->id)
            ->with(['user:id,name', 'workWeek.closures'])
            ->orderByDesc('work_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Dni pracy: data, osoby, godziny i czy są już zatwierdzone.
     *
     * @return Collection<string, array{date: string, people: string, hours: string, approved: bool}>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->entries
            ->groupBy(fn (TimeEntry $entry) => $entry->work_date->toDateString())
            ->map(fn (Collection $entries) => [
                'date' => $entries->first()->work_date->translatedFormat('D d.m.Y'),
                'people' => $entries->pluck('user.name')->unique()->implode(', '),
                'hours' => $this->sum($entries),
                'approved' => $entries->every(fn (TimeEntry $entry) => $this->approved($entry)),
            ]);
    }

    /**
     * Części tygodni z Montageauftrag (zamknięte dla firmy klienta).
     *
     * @return Collection<int, WorkWeek>
     */
    #[Computed]
    public function weeks(): Collection
    {
        return $this->entries
            ->filter(fn (TimeEntry $entry) => $this->approved($entry))
            ->pluck('workWeek')
            ->unique('id')
            ->sortByDesc('starts_on')
            ->values();
    }

    public function approved(TimeEntry $entry): bool
    {
        return $entry->workWeek->isClosedFor($this->project->contractor_id);
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    public function sum(Collection $entries): string
    {
        return (string) $entries->reduce(fn (BigDecimal $sum, TimeEntry $entry) => $sum->plus($entry->hours), BigDecimal::zero());
    }

    public function render()
    {
        return $this->view()->title($this->project->number.' · '.$this->project->name);
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ $project->number }} · {{ $project->name }}</flux:heading>
        <flux:subheading>
            <flux:link :href="route('portal.index')" wire:navigate>{{ __('Projects') }}</flux:link>
            @if ($project->site_name || $project->site_city)
                · {{ collect([$project->site_name, $project->site_city])->filter()->implode(', ') }}
            @endif
        </flux:subheading>
    </div>

    @php($openHours = $this->sum($this->entries->reject(fn ($entry) => $this->approved($entry))))

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text>{{ __('Approved hours') }}</flux:text>
            <flux:heading size="xl">{{ Hours::format($this->sum($this->entries->filter(fn ($entry) => $this->approved($entry)))) }} h</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Hours in progress (not yet approved)') }}</flux:text>
            <flux:heading size="xl" @class(['text-amber-600 dark:text-amber-400' => BigDecimal::of($openHours)->isPositive()])>{{ Hours::format($openHours) }} h</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Working days') }}</flux:text>
            <flux:heading size="xl">{{ $this->days->count() }}</flux:heading>
        </flux:card>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('People') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->days as $key => $day)
                <flux:table.row :key="$key">
                    <flux:table.cell class="whitespace-nowrap">{{ $day['date'] }}</flux:table.cell>
                    <flux:table.cell>{{ $day['people'] }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($day['approved'])
                            <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('In progress') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ Hours::format($day['hours']) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center">{{ __('No working hours yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($this->weeks->isNotEmpty())
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Weekly reports (Montageauftrag)') }}</flux:heading>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->weeks as $week)
                    <flux:button size="sm" icon="document-arrow-down" target="_blank" :href="route('portal.montageauftrag', [$project, $week])">{{ $week->label() }}</flux:button>
                @endforeach
            </div>
        </flux:card>
    @endif
</section>
