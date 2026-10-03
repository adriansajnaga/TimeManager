<?php

use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Livewire\Forms\ProjectForm;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ProjectForm $form;

    public function mount(?Project $project = null): void
    {
        $this->form->setProject($project?->exists ? $project : null);
    }

    public function updatedFormContractorId(): void
    {
        if ($this->form->project === null) {
            $this->form->applyContractorDefaults();
        }
    }

    public function save(): void
    {
        $this->authorize('manage-projects');

        $creating = $this->form->project === null;
        $project = $this->form->save();

        Flux::toast(variant: 'success', text: __('Project saved.'));

        if ($creating) {
            $this->redirectRoute('projects.edit', $project, navigate: true);
        }
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function contractors(): Collection
    {
        return Contractor::query()->clients()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        return $this->view()->title($this->form->project?->fullName() ?? __('New project'));
    }
}; ?>

<section class="w-full max-w-5xl">
    <form wire:submit="save" class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $form->project?->fullName() ?? __('New project') }}</flux:heading>
                <flux:subheading>
                    <flux:link :href="route('projects.index')" wire:navigate>{{ __('Projects') }}</flux:link>
                </flux:subheading>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-project-button">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Project') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <flux:select wire:model.live="form.contractor_id" :label="__('Client')" required>
                        <flux:select.option value="">{{ __('Choose a client') }}</flux:select.option>
                        @foreach ($this->contractors as $client)
                            <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:select wire:model="form.status" :label="__('Status')">
                    @foreach (ProjectStatus::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="form.number" :label="__('Project number')" required />
                <div class="lg:col-span-2">
                    <flux:input wire:model="form.name" :label="__('Project name')" required />
                </div>

                <div class="lg:col-span-3">
                    <flux:input
                        wire:model="form.invoice_label"
                        :label="__('Label on invoice')"
                        :description="__('Projects with the same label are listed once on the invoice. Empty = project name.')"
                    />
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Site') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-3">
                    <flux:input wire:model="form.site_name" :label="__('End customer / site')" />
                </div>
                <div class="lg:col-span-3">
                    <flux:input wire:model="form.site_street" :label="__('Street')" />
                </div>
                <flux:input wire:model="form.site_zip" :label="__('Postal code')" />
                <flux:input wire:model="form.site_city" :label="__('City')" />
                <flux:input wire:model="form.site_country" :label="__('Country code')" maxlength="2" />
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Billing and mileage') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-3">
                <flux:select wire:model.live="form.billing_type" :label="__('Billing type')">
                    @foreach (ProjectBillingType::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($form->billing_type === ProjectBillingType::Fixed->value)
                    <flux:input wire:model="form.contract_value" :label="__('Contract value (net)')" inputmode="decimal" />
                    <flux:input wire:model="form.contract_currency" :label="__('Currency')" maxlength="3" />
                @else
                    <div class="lg:col-span-2"></div>
                @endif

                <flux:input wire:model="form.km_one_way" :label="__('Distance one way (km)')" inputmode="decimal" />

                <div class="lg:col-span-2 self-end">
                    <flux:checkbox
                        wire:model="form.mileage_default"
                        :label="__('Count mileage by default')"
                        :description="__('Default state of the mileage checkbox for new time entries.')"
                    />
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Assigned employees') }}</flux:heading>

            <flux:checkbox.group wire:model="form.user_ids" class="grid gap-2 lg:grid-cols-3">
                @foreach ($this->users as $user)
                    <flux:checkbox :value="(string) $user->id" :label="$user->name" />
                @endforeach
            </flux:checkbox.group>

            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="3" />
        </flux:card>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('projects.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
