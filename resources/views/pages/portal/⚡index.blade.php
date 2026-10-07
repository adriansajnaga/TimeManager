<?php

use App\Models\Project;
use App\Services\ClientPortal;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My projects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Projekty firmy klienta z sumą zatwierdzonych godzin; z godzinami na górze, najnowsze najpierw.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $portal = new ClientPortal(auth()->user());
        $entries = $portal->entries();
        $search = trim($this->search);

        return $portal->projects()
            ->withSum(['timeEntries as approved_hours' => fn ($query) => $query->whereIn('id', (clone $entries)->select('time_entries.id'))], 'hours')
            ->withMax(['timeEntries as last_day' => fn ($query) => $query->whereIn('id', (clone $entries)->select('time_entries.id'))], 'work_date')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')
                ->orWhere('site_name', 'like', '%'.$search.'%')
                ->orWhere('site_city', 'like', '%'.$search.'%')))
            ->orderByRaw('last_day IS NULL')
            ->orderByDesc('last_day')
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('My projects') }}</flux:heading>
        <flux:subheading>{{ auth()->user()->contractor?->name }} · {{ __('Approved working hours and weekly reports.') }}</flux:subheading>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by number, name or place')" class="max-w-sm" />

    <flux:table :paginate="$this->projects">
        <flux:table.columns>
            <flux:table.column>{{ __('Number') }}</flux:table.column>
            <flux:table.column>{{ __('Project') }}</flux:table.column>
            <flux:table.column>{{ __('Place') }}</flux:table.column>
            <flux:table.column>{{ __('Last working day') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->projects as $project)
                <flux:table.row :key="$project->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('portal.project', $project)" wire:navigate>{{ $project->number }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-72 truncate">{{ $project->name }}</flux:table.cell>
                    <flux:table.cell>{{ collect([$project->site_name, $project->site_city])->filter()->implode(', ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $project->last_day ? \Carbon\CarbonImmutable::parse($project->last_day)->format('d.m.Y') : '—' }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ \App\Support\Hours::format((string) ($project->approved_hours ?? 0)) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">{{ __('No projects found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
