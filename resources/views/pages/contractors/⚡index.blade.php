<?php

use App\Enums\ContractorType;
use App\Models\Contractor;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Contractors')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $showInactive = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'showInactive'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Contractor>
     */
    #[Computed]
    public function contractors(): LengthAwarePaginator
    {
        return Contractor::query()
            ->withCount('projects')
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('city', 'like', '%'.$this->search.'%')
                    ->orWhere('tax_id', 'like', '%'.$this->search.'%');
            }))
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when(! $this->showInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Contractors') }}</flux:heading>
            <flux:subheading>{{ __('Clients and suppliers with their billing and document settings.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('contractors.create')" wire:navigate>
            {{ __('New contractor') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name, city or tax ID')" class="max-w-sm" />

        <flux:select wire:model.live="type" class="max-w-48">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (ContractorType::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox wire:model.live="showInactive" :label="__('Show inactive')" />
    </div>

    <flux:table :paginate="$this->contractors">
        <flux:table.columns>
            <flux:table.column>{{ __('Contractor') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('City') }}</flux:table.column>
            <flux:table.column>{{ __('Tax ID') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Hourly rate') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Projects') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->contractors as $contractor)
                <flux:table.row :key="$contractor->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('contractors.show', $contractor)" wire:navigate>{{ $contractor->name }}</flux:link>
                        @unless ($contractor->is_active)
                            <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Inactive') }}</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell>{{ $contractor->type->label() }}</flux:table.cell>
                    <flux:table.cell>{{ trim($contractor->city.' ('.$contractor->country_code.')') }}</flux:table.cell>
                    <flux:table.cell>{{ $contractor->vatId() ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ $contractor->hourly_rate !== null ? $contractor->hourly_rate.' '.$contractor->currency : '—' }}
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $contractor->projects_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('contractors.edit', $contractor)" wire:navigate :aria-label="__('Edit')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">{{ __('No contractors found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
