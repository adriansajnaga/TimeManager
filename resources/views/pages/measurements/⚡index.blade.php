<?php

use App\Models\MeasurementProtocol;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Measurements')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, MeasurementProtocol>
     */
    #[Computed]
    public function protocols(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return MeasurementProtocol::query()
            ->with('contractor')
            ->withCount('boards')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', '%'.$search.'%')
                    ->orWhere('place', 'like', '%'.$search.'%')
                    ->orWhere('investor', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('contractor', fn ($contractors) => $contractors->where('name', 'like', '%'.$search.'%'));
            }))
            ->latest('measured_on')
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Measurements') }}</flux:heading>
            <flux:subheading>{{ __('Electrical measurement protocols: enter results on site, generate the report.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="wrench-screwdriver" :href="route('measurements.equipment')" wire:navigate>{{ __('Instruments and people') }}</flux:button>
            <flux:button variant="primary" icon="plus" :href="route('measurements.create')" wire:navigate>{{ __('New protocol') }}</flux:button>
        </div>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by number, place, investor or client')" class="max-w-sm" />

    <flux:table :paginate="$this->protocols">
        <flux:table.columns>
            <flux:table.column>{{ __('Number') }}</flux:table.column>
            <flux:table.column>{{ __('Measurement place') }}</flux:table.column>
            <flux:table.column>{{ __('Investor') }}</flux:table.column>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Next test due') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->protocols as $protocol)
                <flux:table.row :key="$protocol->id">
                    <flux:table.cell variant="strong" class="whitespace-nowrap">
                        <flux:link :href="route('measurements.show', $protocol)" wire:navigate>{{ $protocol->number }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-72 truncate">
                        {{ $protocol->place }}
                        @if ($protocol->description)
                            <div class="truncate text-xs text-zinc-500">{{ $protocol->description }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="max-w-56 truncate">{{ $protocol->contractor?->name ?? $protocol->investor ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $protocol->measured_on->format('d.m.Y') }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        @if ($protocol->next_test_on)
                            <flux:badge size="sm" :color="$protocol->next_test_on->isPast() ? 'red' : ($protocol->next_test_on->lte(now()->addDays(60)) ? 'amber' : 'zinc')">
                                {{ $protocol->next_test_on->format('m.Y') }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">{{ __('No protocols yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
