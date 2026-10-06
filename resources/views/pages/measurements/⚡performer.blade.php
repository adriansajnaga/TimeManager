<?php

use App\Livewire\ComponentWithAttachments;
use App\Models\Contracts\Attachable;
use App\Models\MeasurementPerformer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;

new class extends ComponentWithAttachments {
    public ?MeasurementPerformer $performer = null;

    public string $name = '';

    public string $certificates = '';

    public bool $is_active = true;

    public function mount(string $id): void
    {
        if ($id !== 'new') {
            $this->performer = MeasurementPerformer::query()->findOrFail((int) $id);
            $this->fill([
                'name' => $this->performer->name,
                'certificates' => (string) $this->performer->certificates,
                'is_active' => $this->performer->is_active,
            ]);
        }
    }

    public function save(): void
    {
        $this->authorize('manage-measurements');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'certificates' => ['nullable', 'string', 'max:2000'],
            ...$this->uploadRules(),
        ]);

        $creating = $this->performer === null;
        $performer = $this->performer ?? new MeasurementPerformer;
        $performer->fill([
            'name' => trim($this->name),
            'certificates' => trim($this->certificates) ?: null,
            'is_active' => $this->is_active,
        ])->save();

        $this->storeUploads($performer);

        Flux::toast(variant: 'success', text: __('Person saved.'));

        if ($creating) {
            $this->redirectRoute('measurements.performer', $performer, navigate: true);
        }
    }

    protected function attachmentOwner(): (Model&Attachable)|null
    {
        return $this->performer;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-measurements';
    }

    public function render()
    {
        return $this->view()->title($this->performer?->name ?? __('New person'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $performer?->name ?? __('New person') }}</flux:heading>
                <flux:subheading><flux:link :href="route('measurements.equipment')" wire:navigate>{{ __('Instruments and people') }}</flux:link></flux:subheading>
            </div>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:textarea wire:model="certificates" :label="__('Qualification certificates (one per line)')" rows="3"
                :placeholder="__('e.g. Świadectwo kwalifikacyjne E1/…')" />
            <flux:checkbox wire:model="is_active" :label="__('Active (selected in new protocols)')" />
        </flux:card>

        <x-attachments :owner="$performer" :uploads="$uploads" :heading="__('Certificate scans')" />
    </form>
</section>
