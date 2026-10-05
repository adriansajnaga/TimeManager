<?php

use App\Livewire\ComponentWithAttachments;
use App\Models\Contracts\Attachable;
use App\Models\MeasurementInstrument;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;

new class extends ComponentWithAttachments {
    public ?MeasurementInstrument $instrument = null;

    public string $name = '';

    public string $serial_number = '';

    public string $calibrated_on = '';

    public string $calibration_valid_until = '';

    public bool $is_active = true;

    public function mount(string $instrument): void
    {
        if ($instrument !== 'new') {
            $this->instrument = MeasurementInstrument::query()->findOrFail((int) $instrument);
            $this->fill([
                'name' => $this->instrument->name,
                'serial_number' => (string) $this->instrument->serial_number,
                'calibrated_on' => $this->instrument->calibrated_on?->toDateString() ?? '',
                'calibration_valid_until' => $this->instrument->calibration_valid_until?->toDateString() ?? '',
                'is_active' => $this->instrument->is_active,
            ]);
        }
    }

    public function save(): void
    {
        $this->authorize('manage-measurements');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'calibrated_on' => ['nullable', 'date_format:Y-m-d'],
            'calibration_valid_until' => ['nullable', 'date_format:Y-m-d'],
            ...$this->uploadRules(),
        ]);

        $creating = $this->instrument === null;
        $instrument = $this->instrument ?? new MeasurementInstrument;
        $instrument->fill([
            'name' => trim($this->name),
            'serial_number' => trim($this->serial_number) ?: null,
            'calibrated_on' => $this->calibrated_on ?: null,
            'calibration_valid_until' => $this->calibration_valid_until ?: null,
            'is_active' => $this->is_active,
        ])->save();

        $this->storeUploads($instrument);

        Flux::toast(variant: 'success', text: __('Instrument saved.'));

        if ($creating) {
            $this->redirectRoute('measurements.instrument', $instrument, navigate: true);
        }
    }

    protected function attachmentOwner(): (Model&Attachable)|null
    {
        return $this->instrument;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-measurements';
    }

    public function render()
    {
        return $this->view()->title($this->instrument?->name ?? __('New instrument'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $instrument?->name ?? __('New instrument') }}</flux:heading>
                <flux:subheading><flux:link :href="route('measurements.equipment')" wire:navigate>{{ __('Instruments and people') }}</flux:link></flux:subheading>
            </div>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <flux:input wire:model="name" :label="__('Instrument')" placeholder="METREL Eurotest AT MI3101" required />
            <flux:input wire:model="serial_number" :label="__('Serial number')" />
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="calibrated_on" type="date" :label="__('Calibrated on')" />
                <flux:input wire:model="calibration_valid_until" type="date" :label="__('Calibration valid until')" />
            </div>
            <flux:checkbox wire:model="is_active" :label="__('Active (offered in new protocols)')" />
        </flux:card>

        <x-attachments :owner="$instrument" :uploads="$uploads" :heading="__('Calibration certificate')" />
    </form>
</section>
