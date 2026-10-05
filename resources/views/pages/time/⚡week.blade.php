<?php

use App\Enums\Permission;
use App\Enums\WorkType;
use App\Livewire\Forms\TimeEntryForm;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use App\Support\Hours;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Working time')] class extends Component {
    /** Tydzień ISO, np. "2026-W31". */
    #[Url]
    public string $week = '';

    /** Osoba, której godziny są pokazane (wybór tylko dla administratora). */
    #[Url(except: '')]
    public string $person = '';

    public TimeEntryForm $form;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-W\d{2}$/', $this->week)) {
            $this->week = CarbonImmutable::today()->format('o-\WW');
        }

        if (! $this->canSeeOthers()) {
            $this->person = '';
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

    public function create(?string $date = null, ?int $projectId = null): void
    {
        $this->form->setEntry(null, $date ?? $this->defaultDate(), $projectId, $this->owner->id);

        Flux::modal('time-entry')->show();
    }

    public function edit(TimeEntry $entry): void
    {
        $this->authorize('update', $entry);
        $this->form->setEntry($entry);

        Flux::modal('time-entry')->show();
    }

    public function updatedFormProjectId(): void
    {
        if ($this->form->entry === null) {
            $this->form->applyProjectDefaults();
        }
    }

    public function save(): void
    {
        $this->authorize('log-own-time');

        if ($this->form->entry !== null) {
            $this->authorize('update', $this->form->entry);
        }

        $entry = $this->form->save();

        // Po dodaniu wpisu w innym tygodniu przechodzimy do tego tygodnia.
        $this->week = $entry->work_date->format('o-\WW');

        Flux::modal('time-entry')->close();
        Flux::toast(variant: 'success', text: __('Working time saved.'));
    }

    public function delete(TimeEntry $entry): void
    {
        $this->authorize('delete', $entry);
        $entry->delete();

        Flux::toast(variant: 'success', text: __('Entry deleted.'));
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

    #[Computed]
    public function owner(): User
    {
        if ($this->person !== '' && $this->canSeeOthers()) {
            return User::query()->findOrFail($this->person);
        }

        return Auth::user();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return TimeEntry::query()
            ->with(['project', 'workWeek'])
            ->where('user_id', $this->owner->id)
            ->whereBetween('work_date', [$this->monday->toDateString(), $this->monday->addDays(6)->toDateString()])
            ->orderBy('work_date')
            ->orderBy('start_time')
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
            ->sortByDesc(fn (array $row) => $row['project']->id)
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
     * Części tego tygodnia (po miesiącach) i ich stan.
     *
     * @return Collection<int, WorkWeek>
     */
    #[Computed]
    public function parts(): Collection
    {
        return WorkWeek::query()
            ->where('iso_year', $this->monday->isoWeekYear())
            ->where('iso_week', $this->monday->isoWeek())
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function availableProjects(): Collection
    {
        $owner = User::find($this->form->user_id) ?? $this->owner;

        return Project::query()->availableFor($owner)->with('contractor')->latest('id')->get();
    }

    public function canSeeOthers(): bool
    {
        return Auth::user()->hasPermission(Permission::ViewAllTimeEntries);
    }

    private function defaultDate(): string
    {
        $today = CarbonImmutable::today();

        return $today->betweenIncluded($this->monday, $this->monday->addDays(6))
            ? $today->toDateString()
            : $this->monday->toDateString();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Working time') }}</flux:heading>
            <flux:subheading>{{ $this->owner->name }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-entry-button">{{ __('Add entry') }}</flux:button>
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

        @foreach ($this->parts as $part)
            <flux:badge size="sm" :color="$part->isClosed() ? 'green' : 'zinc'" :icon="$part->isClosed() ? 'lock-closed' : 'lock-open'">
                {{ $part->starts_on->translatedFormat('F') }}: {{ $part->isClosed() ? __('closed') : __('open') }}
            </flux:badge>
        @endforeach

        @if ($this->canSeeOthers())
            <flux:select wire:model.live="person" class="ms-auto max-w-56">
                <flux:select.option value="">{{ __('My hours') }}</flux:select.option>
                @foreach ($this->people as $user)
                    @unless ($user->id === auth()->id())
                        <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                    @endunless
                @endforeach
            </flux:select>
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
                        <span class="font-medium">{{ $row['project']->number }}</span>
                        <span class="text-zinc-500">{{ $row['project']->name }}</span>
                    </flux:table.cell>
                    @foreach ($this->days as $index => $day)
                        <flux:table.cell align="center" @class(['bg-zinc-50 dark:bg-zinc-900/40' => $day->isWeekend()])>
                            <button type="button" class="w-full rounded px-1 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                wire:click="create('{{ $day->toDateString() }}', {{ $row['project']->id }})">
                                {{ isset($row['days'][$index]) ? Hours::format($row['days'][$index]) : '·' }}
                            </button>
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

    {{-- Wpisy dzień po dniu --}}
    <div class="space-y-4">
        @foreach ($this->entries->groupBy(fn ($entry) => $entry->work_date->toDateString()) as $date => $dayEntries)
            <div wire:key="day-{{ $date }}">
                <flux:heading size="sm" class="mb-2">{{ CarbonImmutable::parse($date)->translatedFormat('l, d.m.Y') }}</flux:heading>

                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($dayEntries as $entry)
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-1 px-3 py-2 text-sm" wire:key="entry-{{ $entry->id }}">
                            <div class="w-28 font-medium tabular-nums">{{ $entry->startLabel() }}–{{ $entry->endLabel() }}</div>
                            <div class="w-24 tabular-nums">
                                {{ Hours::format($entry->hours) }} h
                                @if ($entry->break_minutes)
                                    <span class="text-xs text-zinc-500">(−{{ $entry->break_minutes }})</span>
                                @endif
                            </div>
                            <div class="min-w-48 flex-1">
                                <div class="font-medium">{{ $entry->project->fullName() }}</div>
                                @if ($entry->description)
                                    <div class="text-zinc-500">{{ $entry->description }}</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($entry->work_type === WorkType::Demontage)
                                    <flux:badge size="sm">{{ $entry->work_type->label() }}</flux:badge>
                                @endif
                                @if ($entry->count_mileage)
                                    <flux:badge size="sm" color="blue" icon="truck">{{ __('km') }}</flux:badge>
                                @endif
                                @can('update', $entry)
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $entry->id }})" :aria-label="__('Edit')" />
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Delete this entry?') }}" :aria-label="__('Delete')" />
                                @else
                                    <flux:icon.lock-closed variant="micro" class="text-zinc-400" />
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Formularz wpisu --}}
    <flux:modal name="time-entry" class="md:w-[34rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $form->entry ? __('Edit entry') : __('New entry') }}</flux:heading>

            @if ($this->canSeeOthers())
                <flux:select wire:model.live="form.user_id" :label="__('Person')">
                    @foreach ($this->people as $user)
                        <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="form.work_date" type="date" :label="__('Date')" required />

            <x-searchable-select wire:model.live="form.project_id" :label="__('Project')"
                :placeholder="__('Choose a project')" :search-placeholder="__('Search by number, name, client or place')"
                :options="$this->availableProjects->map(fn ($project) => [
                    'value' => $project->id,
                    'label' => $project->fullName(),
                    'search' => implode(' ', array_filter([$project->contractor?->name, $project->site_name, $project->site_city])),
                ])->all()" />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="form.start_time" :label="__('Start')" required>
                    <flux:select.option value="">--:--</flux:select.option>
                    @foreach (Hours::quarterTimes() as $time)
                        <flux:select.option :value="$time">{{ $time }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="form.end_time" :label="__('End')" required>
                    <flux:select.option value="">--:--</flux:select.option>
                    @foreach (Hours::quarterTimes(withMidnight: true) as $time)
                        <flux:select.option :value="$time">{{ $time }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:radio.group wire:model.live="form.break_minutes" :label="__('Break (minutes)')" variant="segmented">
                @foreach (TimeEntry::BREAKS as $minutes)
                    <flux:radio :value="$minutes" :label="(string) $minutes" />
                @endforeach
            </flux:radio.group>

            <flux:callout :variant="$form->calculatedHours() ? 'success' : 'secondary'" icon="clock">
                <flux:callout.text>
                    @if ($form->calculatedHours())
                        {{ __('Hours worked') }}: <strong>{{ Hours::format($form->calculatedHours()) }}</strong>
                    @else
                        {{ __('Choose start, end and break to calculate the hours.') }}
                    @endif
                </flux:callout.text>
            </flux:callout>

            <flux:radio.group wire:model="form.work_type" :label="__('Type of work')" variant="segmented">
                @foreach (WorkType::cases() as $type)
                    <flux:radio :value="$type->value" :label="$type->label()" />
                @endforeach
            </flux:radio.group>

            <flux:textarea wire:model="form.description" :label="__('Work description')" rows="3" />

            <flux:checkbox wire:model="form.count_mileage" :label="__('Count mileage')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
