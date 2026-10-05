<?php

use App\Enums\ProjectStatus;
use App\Models\Contractor;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $contractor = '';

    #[Url(except: 'active')]
    public string $status = 'active';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'contractor', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        // Nierozliczone na górze, w grupie od najmłodszego.
        return Project::query()
            ->withUnsettled()
            ->with('contractor')
            ->withCount('users')
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%')
                    ->orWhere('site_name', 'like', '%'.$this->search.'%')
                    ->orWhere('site_city', 'like', '%'.$this->search.'%');
            }))
            ->when($this->contractor !== '', fn ($query) => $query->where('contractor_id', $this->contractor))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderByDesc('is_unsettled')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function contractors(): Collection
    {
        return Contractor::query()->clients()->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Projects') }}</flux:heading>
            <flux:subheading>{{ __('Project numbers, sites and billing type.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>
            {{ __('New project') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by number, name or site')" class="max-w-sm" />

        <flux:select wire:model.live="contractor" class="max-w-64">
            <flux:select.option value="">{{ __('All clients') }}</flux:select.option>
            @foreach ($this->contractors as $client)
                <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" class="max-w-40">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (ProjectStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->projects">
        <flux:table.columns>
            <flux:table.column>{{ __('Number') }}</flux:table.column>
            <flux:table.column>{{ __('Project name') }}</flux:table.column>
            <flux:table.column>{{ __('Site') }}</flux:table.column>
            <flux:table.column>{{ __('Client') }}</flux:table.column>
            <flux:table.column>{{ __('Billing') }}</flux:table.column>
            <flux:table.column align="end">{{ __('km one way') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->projects as $project)
                <flux:table.row :key="$project->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('projects.show', $project)" wire:navigate>{{ $project->number }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-72 truncate">
                        {{ $project->name }}
                        @if ($project->is_unsettled)
                            <flux:badge size="sm" color="amber" class="ms-2">{{ __('Unsettled') }}</flux:badge>
                        @endif
                        @if ($project->status === ProjectStatus::Closed)
                            <flux:badge size="sm" color="zinc" class="ms-2">{{ $project->status->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ collect([$project->site_name, $project->site_city])->filter()->implode(', ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $project->contractor->name }}</flux:table.cell>
                    <flux:table.cell>{{ $project->billing_type->label() }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $project->km_one_way ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate :aria-label="__('Edit')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">{{ __('No projects found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
