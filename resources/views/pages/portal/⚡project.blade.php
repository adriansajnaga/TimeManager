<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WeeklyReport;
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
     * Wszystkie wpisy projektu (bez stawek) — zatwierdzone i w trakcie.
     *
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return (new ClientPortal(auth()->user()))->entries()
            ->where('project_id', $this->project->id)
            ->with('user:id,name')
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Przebieg projektu tydzień po tygodniu (najnowsze najpierw): wpisy dzienne, a na końcu Montageauftrag,
     * gdy część tygodnia jest zamknięta dla firmy klienta.
     *
     * @return Collection<int, array{week: WorkWeek, approved: bool, entries: Collection<int, TimeEntry>, hours: string, report: WeeklyReport|null}>
     */
    #[Computed]
    public function weeks(): Collection
    {
        $contractorId = $this->project->contractor_id;
        $weeks = WorkWeek::query()->whereIn('id', $this->entries->pluck('work_week_id')->unique())->with('closures')->get()->keyBy('id');
        $reports = WeeklyReport::query()
            ->where('project_id', $this->project->id)
            ->whereIn('work_week_id', $weeks->keys())
            ->with(['materials' => fn ($query) => $query->orderBy('position')])
            ->get()
            ->keyBy('work_week_id');

        return $this->entries
            ->groupBy('work_week_id')
            ->map(function (Collection $entries, int $weekId) use ($weeks, $reports, $contractorId) {
                $week = $weeks[$weekId];
                $approved = $week->isClosedFor($contractorId);

                return [
                    'week' => $week,
                    'approved' => $approved,
                    'entries' => $entries,
                    'hours' => $this->sum($entries),
                    'report' => $approved ? $reports->get($weekId) : null,
                ];
            })
            ->sortByDesc(fn (array $item) => $item['week']->starts_on)
            ->values();
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
            <flux:link :href="route('portal.index')" wire:navigate>{{ __('My projects') }}</flux:link>
            @if ($project->site_name || $project->site_city)
                · {{ collect([$project->site_name, $project->site_city])->filter()->implode(', ') }}
            @endif
        </flux:subheading>
    </div>

    @php($approvedHours = $this->sum($this->weeks->where('approved', true)->pluck('entries')->flatten(1)))
    @php($openHours = $this->sum($this->weeks->where('approved', false)->pluck('entries')->flatten(1)))

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text>{{ __('Approved hours') }}</flux:text>
            <flux:heading size="xl">{{ Hours::format($approvedHours) }} h</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Hours in progress (not yet approved)') }}</flux:text>
            <flux:heading size="xl" @class(['text-amber-600 dark:text-amber-400' => BigDecimal::of($openHours)->isPositive()])>{{ Hours::format($openHours) }} h</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Working days') }}</flux:text>
            <flux:heading size="xl">{{ $this->entries->pluck('work_date')->map->toDateString()->unique()->count() }}</flux:heading>
        </flux:card>
    </div>

    @forelse ($this->weeks as $item)
        <flux:card class="space-y-4" wire:key="week-{{ $item['week']->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ $item['week']->label() }}</flux:heading>
                <div class="flex items-center gap-2">
                    @if ($item['approved'])
                        <flux:badge size="sm" color="green" icon="check-circle">{{ __('Approved') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber" icon="clock">{{ __('In progress — not yet approved') }}</flux:badge>
                    @endif
                    <flux:badge size="sm">{{ Hours::format($item['hours']) }} h</flux:badge>
                </div>
            </div>

            {{-- Przebieg tygodnia: dzień, osoba, godziny, opis --}}
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($item['entries'] as $entry)
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 px-3 py-2" wire:key="entry-{{ $entry->id }}">
                        <div class="w-44 shrink-0 tabular-nums">
                            {{ $entry->work_date->translatedFormat('D d.m.Y') }}
                            <div class="text-xs text-zinc-500">{{ $entry->user->name }}</div>
                        </div>
                        <div class="w-32 shrink-0 tabular-nums text-zinc-500">
                            {{ $entry->startLabel() }}–{{ $entry->endLabel() }}
                            <div class="font-medium text-zinc-800 dark:text-zinc-100">{{ Hours::format($entry->hours) }} h</div>
                        </div>
                        <div class="min-w-48 flex-1 text-zinc-600 dark:text-zinc-300">{{ $entry->description ?: '—' }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Na końcu tygodnia: Montageauftrag --}}
            @if ($item['report'])
                <div class="space-y-3 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading>{{ __('Weekly report (Montageauftrag)') }}</flux:heading>
                        <flux:button size="sm" icon="document-arrow-down" target="_blank" :href="route('portal.montageauftrag', [$project, $item['week']])">{{ __('PDF') }}</flux:button>
                    </div>
                    @if (filled($item['report']->performed_work))
                        <div>
                            <flux:text class="text-xs uppercase">{{ __('Work performed') }}</flux:text>
                            <div class="whitespace-pre-line text-sm">{{ $item['report']->performed_work }}</div>
                        </div>
                    @endif
                    @if (filled($item['report']->remaining_work))
                        <div>
                            <flux:text class="text-xs uppercase">{{ __('Remaining work') }}</flux:text>
                            <div class="whitespace-pre-line text-sm">{{ $item['report']->remaining_work }}</div>
                        </div>
                    @endif
                    @if ($item['report']->materials->isNotEmpty())
                        <div>
                            <flux:text class="text-xs uppercase">{{ __('Material') }}</flux:text>
                            <ul class="text-sm">
                                @foreach ($item['report']->materials as $material)
                                    <li>{{ $material->name }}@if (filled($material->quantity)) — {{ $material->quantity }} {{ $material->unit }}@endif</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @elseif (! $item['approved'])
                <flux:text class="text-sm">{{ __('The weekly report will be available once the week is approved.') }}</flux:text>
            @endif
        </flux:card>
    @empty
        <flux:callout icon="information-circle">
            <flux:callout.text>{{ __('No working hours yet.') }}</flux:callout.text>
        </flux:callout>
    @endforelse
</section>
