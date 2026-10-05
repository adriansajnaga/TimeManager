<?php

use App\Enums\Permission;
use App\Models\Contractor;
use App\Models\WorkWeek;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Weeks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    /** Klient i zaznaczone zamknięte części tygodni do Stundenzettel. */
    public string $client = '';

    /** @var list<string> */
    public array $selected = [];

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, WorkWeek>
     */
    #[Computed]
    public function weeks(): LengthAwarePaginator
    {
        $user = Auth::user();
        $limitToAssigned = ! $user->hasPermission(Permission::ViewAllTimeEntries);
        $assigned = fn (Builder $query) => $query->whereHas('project.users', fn (Builder $users) => $users->whereKey($user->id));

        return WorkWeek::query()
            ->whereHas('timeEntries', $limitToAssigned ? $assigned : fn () => null)
            ->withSum(['timeEntries as hours_sum' => $limitToAssigned ? $assigned : fn () => null], 'hours')
            ->withCount([
                'timeEntries as projects_count' => fn (Builder $query) => $query->select(DB::raw('count(distinct project_id)')),
                'weeklyReports as complete_reports_count' => fn (Builder $query) => $query->whereNotNull('performed_work')->where('performed_work', '<>', ''),
                'closures',
            ])
            // Klienci z godzinami — część jest zamknięta, gdy zamknięto ją dla każdego z nich.
            ->addSelect(['clients_count' => DB::table('time_entries')
                ->join('projects', 'projects.id', '=', 'time_entries.project_id')
                ->whereColumn('time_entries.work_week_id', 'work_weeks.id')
                ->selectRaw('count(distinct projects.contractor_id)')])
            ->when($this->status === 'open', fn (Builder $query) => $query->whereNull('closed_at'))
            ->when($this->status === 'closed', fn (Builder $query) => $query->whereNotNull('closed_at'))
            ->orderByDesc('iso_year')
            ->orderByDesc('iso_week')
            ->orderByDesc('month')
            ->paginate(20);
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Contractor::query()->clients()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function canCreateTimesheet(): bool
    {
        return Auth::user()->hasPermission(Permission::ManageSettlements);
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Weeks') }}</flux:heading>
        <flux:subheading>{{ __('Each calendar week is split by month. Describe the work, then close the week to make it billable.') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="status" class="max-w-48">
            <flux:select.option value="">{{ __('All weeks') }}</flux:select.option>
            <flux:select.option value="open">{{ str(__('open'))->ucfirst() }}</flux:select.option>
            <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
        </flux:select>

        @if ($this->canCreateTimesheet())
            <div class="ms-auto flex flex-wrap items-end gap-2">
                <flux:select wire:model.live="client" class="max-w-64">
                    <flux:select.option value="">{{ __('Timesheet for client…') }}</flux:select.option>
                    @foreach ($this->clients as $contractor)
                        <flux:select.option :value="$contractor->id">{{ $contractor->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button
                    icon="document-arrow-down"
                    :href="route('documents.stundenzettel', ['client' => $client, 'weeks' => $selected])"
                    target="_blank"
                    :disabled="$client === '' || $selected === []"
                >
                    {{ __('Timesheet PDF') }} ({{ count($selected) }})
                </flux:button>
            </div>
        @endif
    </div>

    <flux:table :paginate="$this->weeks">
        <flux:table.columns>
            @if ($this->canCreateTimesheet())
                <flux:table.column class="w-8"></flux:table.column>
            @endif
            <flux:table.column>{{ __('Week') }}</flux:table.column>
            <flux:table.column>{{ __('Month') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Projects') }}</flux:table.column>
            <flux:table.column>{{ __('Descriptions') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->weeks as $week)
                <flux:table.row :key="$week->id">
                    @if ($this->canCreateTimesheet())
                        <flux:table.cell>
                            @if ($week->closures_count > 0)
                                <flux:checkbox wire:model.live="selected" :value="(string) $week->id" />
                            @endif
                        </flux:table.cell>
                    @endif
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('weeks.show', $week)" wire:navigate>{{ $week->label() }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $week->starts_on->translatedFormat('F Y') }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Hours::format((string) $week->hours_sum) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $week->projects_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$week->complete_reports_count >= $week->projects_count ? 'green' : 'amber'">
                            {{ $week->complete_reports_count }}/{{ $week->projects_count }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($week->isClosed())
                            <flux:badge size="sm" color="green" icon="lock-closed">{{ __('Closed') }}</flux:badge>
                        @elseif ($week->closures_count > 0)
                            <flux:badge size="sm" color="amber" icon="lock-open">{{ __(':closed of :total clients closed', ['closed' => $week->closures_count, 'total' => $week->clients_count]) }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" icon="lock-open">{{ str(__('open'))->ucfirst() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="arrow-right" :href="route('weeks.show', $week)" wire:navigate :aria-label="__('Open')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center">{{ __('No weeks with working time yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
