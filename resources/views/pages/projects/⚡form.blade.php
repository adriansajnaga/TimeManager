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

    /** Projekt, z którego przepisano miejsce (ukrywa podpowiedzi). */
    public ?int $copiedFrom = null;

    /**
     * Wcześniejsze projekty o podobnym miejscu/etykiecie — po jednym na adres, najnowsze najpierw.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function siteSuggestions(): Collection
    {
        $terms = array_values(array_filter(
            [trim($this->form->site_name), trim($this->form->invoice_label), trim($this->form->site_city)],
            fn (string $term) => mb_strlen($term) >= 2,
        ));

        // Tylko przy nowym projekcie — przy edycji podpowiedzi by przeszkadzały.
        if ($terms === [] || $this->copiedFrom !== null || $this->form->project !== null) {
            return new Collection;
        }

        return Project::query()
            ->with('contractor')
            ->when($this->form->project, fn ($query) => $query->whereKeyNot($this->form->project->id))
            ->when($this->form->contractor_id, fn ($query) => $query->where('contractor_id', $this->form->contractor_id))
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    foreach (['site_name', 'invoice_label', 'site_city', 'name'] as $column) {
                        $query->orWhere($column, 'like', '%'.$term.'%');
                    }
                }
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->unique(fn (Project $project) => mb_strtolower(trim($project->site_name.'|'.$project->site_street.'|'.$project->site_city)))
            ->take(6)
            ->values();
    }

    public function copySite(int $projectId): void
    {
        $this->form->copySiteFrom(Project::query()->findOrFail($projectId));
        $this->copiedFrom = $projectId;

        Flux::toast(text: __('Site data copied from the earlier project.'));
    }

    public function updatedForm(mixed $value, string $key): void
    {
        // Po zmianie wyszukiwanych pól pokaż znowu podpowiedzi.
        if (in_array($key, ['site_name', 'invoice_label', 'site_city'], true) && $this->copiedFrom !== null && $this->form->project === null) {
            $this->copiedFrom = null;
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
                        wire:model.live.debounce.400ms="form.invoice_label"
                        :label="__('Label on invoice')"
                        :description="__('Place of work on the invoice; the same label is listed once. Empty = end customer / site.')"
                    />
                </div>
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Site') }}</flux:heading>

            @if ($this->siteSuggestions->isNotEmpty())
                <div class="space-y-2 rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/40">
                    <flux:text size="sm">{{ __('Earlier projects — click to copy the address, distance and settings:') }}</flux:text>
                    <div class="flex flex-col gap-1">
                        @foreach ($this->siteSuggestions as $suggestion)
                            <button type="button" wire:click="copySite({{ $suggestion->id }})" wire:key="site-{{ $suggestion->id }}"
                                class="rounded px-2 py-1 text-start text-sm hover:bg-blue-100 dark:hover:bg-blue-900/50">
                                <strong>{{ $suggestion->invoiceLabel() }}</strong>
                                — {{ collect([$suggestion->site_name, $suggestion->site_street, trim($suggestion->site_zip.' '.$suggestion->site_city)])->filter()->implode(', ') }}
                                @if ($suggestion->km_one_way !== null) · {{ rtrim(rtrim((string) $suggestion->km_one_way, '0'), '.') }} km @endif
                                <span class="text-zinc-500">({{ $suggestion->number }}{{ $form->contractor_id ? '' : ', '.$suggestion->contractor->name }})</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-3">
                    <flux:input wire:model.live.debounce.400ms="form.site_name" :label="__('End customer / site')" />
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
