<?php

use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vehicles')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';
    public string $registration_no = '';
    public bool $is_default = false;

    /**
     * @return Collection<int, Vehicle>
     */
    #[Computed]
    public function vehicles(): Collection
    {
        return Vehicle::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'registration_no', 'is_default');
        $this->resetValidation();
        $this->is_default = $this->vehicles->isEmpty();

        Flux::modal('vehicle')->show();
    }

    public function edit(Vehicle $vehicle): void
    {
        $this->resetValidation();
        $this->editingId = $vehicle->id;
        $this->name = $vehicle->name;
        $this->registration_no = (string) $vehicle->registration_no;
        $this->is_default = $vehicle->is_default;

        Flux::modal('vehicle')->show();
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'registration_no' => ['nullable', 'string', 'max:30'],
            'is_default' => ['boolean'],
        ]);

        $vehicle = $this->editingId ? Vehicle::query()->findOrFail($this->editingId) : new Vehicle;

        $vehicle->fill([
            'name' => trim($this->name),
            'registration_no' => trim($this->registration_no) === '' ? null : strtoupper(trim($this->registration_no)),
            'is_default' => $this->is_default,
        ])->save();

        unset($this->vehicles);
        Flux::modal('vehicle')->close();
        Flux::toast(variant: 'success', text: __('Vehicle saved.'));
    }

    public function delete(Vehicle $vehicle): void
    {
        $this->authorize('manage-settings');

        $vehicle->delete();
        unset($this->vehicles);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Vehicles') }}</flux:heading>
            <flux:subheading>{{ __('Vehicles shown on the mileage allowance.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New vehicle') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Vehicle') }}</flux:table.column>
            <flux:table.column>{{ __('Registration number') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->vehicles as $vehicle)
                <flux:table.row :key="$vehicle->id">
                    <flux:table.cell variant="strong">
                        {{ $vehicle->name }}
                        @if ($vehicle->is_default)
                            <flux:badge size="sm" color="green" class="ms-2">{{ __('Default') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $vehicle->registration_no ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $vehicle->id }})" :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $vehicle->id }})" wire:confirm="{{ __('Delete this vehicle?') }}" :aria-label="__('Delete')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="text-center">{{ __('No vehicles yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="vehicle" class="md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit vehicle') : __('New vehicle') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Vehicle')" :placeholder="__('e.g. Ford')" required />
            <flux:input wire:model="registration_no" :label="__('Registration number')" />
            <flux:switch wire:model="is_default" :label="__('Default vehicle')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
