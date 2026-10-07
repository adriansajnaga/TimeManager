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
     * Zatwierdzone wpisy projektu (bez stawek), najnowsze najpierw.
     *
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return (new ClientPortal(auth()->user()))->entries()
            ->where('project_id', $this->project->id)
            ->with('user:id,name')
            ->orderByDesc('work_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Dni pracy: data, osoby, godziny.
     *
     * @return Collection<string, array{date: string, people: string, hours: string}>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->entries
            ->groupBy(fn (TimeEntry $entry) => $entry->work_date->toDateString())
            ->map(fn (Collection $entries) => [
                'date' => $entries->first()->work_date->translatedFormat('D d.m.Y'),
                'people' => $entries->pluck('user.name')->unique()->implode(', '),
                'hours' => (string) $entries->reduce(fn (BigDecimal $sum, TimeEntry $entry) => $sum->plus($entry->hours), BigDecimal::zero()),
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
        return WorkWeek::query()->whereIn('id', $this->entries->pluck('work_week_id')->unique())->orderByDesc('starts_on')->get();
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

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card>
            <flux:text>{{ __('Approved hours') }}</flux:text>
            <flux:heading size="xl">{{ Hours::format((string) $this->entries->reduce(fn ($sum, $entry) => $sum->plus($entry->hours), BigDecimal::zero())) }} h</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Working days') }}</flux:text>
            <flux:heading size="xl">{{ $this->days->count() }}</flux:heading>
        </flux:card>
    </div>

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

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('People') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->days as $key => $day)
                <flux:table.row :key="$key">
                    <flux:table.cell class="whitespace-nowrap">{{ $day['date'] }}</flux:table.cell>
                    <flux:table.cell>{{ $day['people'] }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ Hours::format($day['hours']) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="text-center">{{ __('No approved hours yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
