<?php

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Livewire\Forms\ContractorForm;
use App\Models\BankAccount;
use App\Models\Contractor;
use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ContractorForm $form;

    public function mount(?Contractor $contractor = null): void
    {
        $this->form->setContractor($contractor?->exists ? $contractor : null);
    }

    public function save(): void
    {
        $this->authorize('manage-contractors');

        $creating = $this->form->contractor === null;
        $contractor = $this->form->save();

        Flux::toast(variant: 'success', text: __('Contractor saved.'));

        if ($creating) {
            $this->redirectRoute('contractors.edit', $contractor, navigate: true);
        }
    }

    public function moveDocument(string $value, int $direction): void
    {
        $this->form->movePackageDocument($value, $direction);
    }

    public function applyDefaultTemplates(): void
    {
        $this->form->applyDefaultTemplates();
    }

    /**
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return BankAccount::query()->orderBy('label')->get();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    #[Computed]
    public function vehicles(): Collection
    {
        return Vehicle::query()->orderBy('name')->get();
    }

    public function render()
    {
        return $this->view()->title($this->form->contractor?->name ?? __('New contractor'));
    }
}; ?>

<section class="w-full max-w-5xl">
    <form wire:submit="save" class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $form->contractor?->name ?? __('New contractor') }}</flux:heading>
                <flux:subheading>
                    <flux:link :href="route('contractors.index')" wire:navigate>{{ __('Contractors') }}</flux:link>
                </flux:subheading>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-contractor-button">{{ __('Save') }}</flux:button>
        </div>

        {{-- Dane podstawowe --}}
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Basic data') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <flux:input wire:model="form.name" :label="__('Company name')" required />
                </div>

                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach (ContractorType::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="self-end">
                    <flux:switch wire:model="form.is_active" :label="__('Active')" />
                </div>

                <flux:input wire:model="form.street" :label="__('Street')" />

                <div class="grid grid-cols-[7rem_1fr] gap-4">
                    <flux:input wire:model="form.zip" :label="__('Postal code')" />
                    <flux:input wire:model="form.city" :label="__('City')" />
                </div>

                <flux:input wire:model="form.country_code" :label="__('Country code')" maxlength="2" :description="__('ISO code, e.g. PL, DE')" />

                <div class="grid grid-cols-[7rem_1fr] gap-4">
                    <flux:input wire:model="form.vat_prefix" :label="__('VAT prefix')" maxlength="2" :placeholder="__('e.g. DE')" />
                    <flux:input wire:model="form.tax_id" :label="__('Tax ID (NIP / VAT ID)')" />
                </div>

                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.phone" :label="__('Phone')" />
            </div>
        </flux:card>

        {{-- Rozliczenie --}}
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Billing') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-3">
                <flux:input wire:model="form.hourly_rate" :label="__('Hourly rate')" inputmode="decimal" />
                <flux:input wire:model="form.km_rate" :label="__('Rate per km')" inputmode="decimal" />
                <flux:input wire:model="form.currency" :label="__('Currency')" maxlength="3" />

                <div class="lg:col-span-2">
                    <flux:input wire:model="form.base_address" :label="__('Mileage start address')" :description="__('Start and end point of each route, e.g. your accommodation.')" />
                </div>

                <flux:select wire:model="form.vehicle_id" :label="__('Vehicle')">
                    <flux:select.option value="">{{ __('Default vehicle') }}</flux:select.option>
                    @foreach ($this->vehicles as $vehicle)
                        <flux:select.option :value="$vehicle->id">{{ $vehicle->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="lg:col-span-2">
                    <flux:select wire:model="form.vat_code" :label="__('VAT rate')">
                        @foreach (VatCode::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:input wire:model="form.payment_days" :label="__('Payment term (days)')" type="number" min="0" max="365" />

                <div class="lg:col-span-2">
                    <flux:select wire:model="form.bank_account_id" :label="__('Bank account')">
                        <flux:select.option value="">{{ __('Default bank account') }}</flux:select.option>
                        @foreach ($this->bankAccounts as $account)
                            <flux:select.option :value="$account->id">{{ $account->displayName() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:select wire:model="form.invoice_line_mode" :label="__('Invoice lines')">
                    @foreach (InvoiceLineMode::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        {{-- Dokumenty --}}
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Documents') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-2">
                <flux:select wire:model="form.document_language" :label="__('Language of work documents and e-mails')">
                    @foreach (Language::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="form.invoice_language" :label="__('Invoice language')">
                    @foreach (InvoiceLanguage::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:field>
                <flux:label>{{ __('PDF package: documents and order') }}</flux:label>

                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($form->package_order as $value)
                        <div class="flex items-center gap-3 px-3 py-2" wire:key="package-{{ $value }}">
                            <flux:checkbox wire:model="form.package_included" :value="$value" />
                            <span class="flex-1 text-sm">{{ PackageDocument::from($value)->label() }}</span>
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveDocument('{{ $value }}', -1)" :disabled="$loop->first" :aria-label="__('Move up')" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveDocument('{{ $value }}', 1)" :disabled="$loop->last" :aria-label="__('Move down')" />
                        </div>
                    @endforeach
                </div>

                <flux:error name="form.package_included" />
            </flux:field>

            <flux:textarea
                wire:model="form.invoice_description_template"
                :label="__('Invoice line description')"
                rows="4"
                :description="__('Placeholders: {period_from}, {period_to}, {projects} (one project label per line).')"
            />

            <div>
                <flux:button size="sm" icon="arrow-path" wire:click="applyDefaultTemplates">
                    {{ __('Insert default templates for the document language') }}
                </flux:button>
            </div>
        </flux:card>

        {{-- E-mail --}}
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('E-mail with invoice') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-2">
                <flux:input wire:model="form.email_to" :label="__('To')" :description="__('Separate several addresses with commas.')" />
                <flux:input wire:model="form.email_cc" :label="__('Cc')" />
            </div>

            <flux:input wire:model="form.email_subject_template" :label="__('Subject')" />

            <flux:textarea
                wire:model="form.email_body_template"
                :label="__('Message')"
                rows="6"
                :description="__('Placeholders: {number} (invoice number), {date} (issue date), {sender}.')"
            />
        </flux:card>

        <flux:card>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="3" />
        </flux:card>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('contractors.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
