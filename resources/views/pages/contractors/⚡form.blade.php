<?php

use App\Enums\ContractorType;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceLineMode;
use App\Enums\Language;
use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Livewire\ComponentWithAttachments;
use App\Livewire\Forms\ContractorForm;
use App\Models\BankAccount;
use App\Models\Contractor;
use App\Models\Note;
use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

new class extends ComponentWithAttachments {
    public ContractorForm $form;

    /** Logo do nagłówka dokumentów klienta (np. Stundennachweis). */
    public ?TemporaryUploadedFile $logo = null;

    public function mount(?Contractor $contractor = null): void
    {
        $this->form->setContractor($contractor?->exists ? $contractor : null);
    }

    public function save(): void
    {
        $this->authorize('manage-contractors');
        $this->validate(['logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'], ...$this->uploadRules()]);

        $creating = $this->form->contractor === null;
        $contractor = $this->form->save();

        if ($this->logo !== null) {
            if ($contractor->logo_path !== null) {
                Storage::disk('local')->delete($contractor->logo_path);
            }

            $contractor->update([
                'logo_path' => $this->logo->storeAs('contractors', $contractor->id.'-logo.'.$this->logo->extension(), 'local'),
            ]);
            $this->logo = null;
        }

        $this->storeUploads($contractor);

        Flux::toast(variant: 'success', text: __('Contractor saved.'));

        if ($creating) {
            $this->redirectRoute('contractors.edit', $contractor, navigate: true);
        }
    }

    /**
     * Powód, dla którego kontrahenta nie można usunąć (null = można).
     */
    public function deleteBlocker(): ?string
    {
        $contractor = $this->form->contractor;

        if ($contractor === null) {
            return null;
        }

        if ($contractor->projects()->exists()) {
            return __('The contractor has projects. Delete or move them first, or mark the contractor as inactive.');
        }

        if (\App\Models\Settlement::query()->where('contractor_id', $contractor->id)->exists()) {
            return __('The contractor has settlements. Mark the contractor as inactive instead.');
        }

        return null;
    }

    /**
     * Usunięcie kontrahenta bez projektów i rozliczeń. Faktury zostają (z kopią danych stron).
     */
    public function delete(): void
    {
        $this->authorize('manage-contractors');

        $contractor = $this->form->contractor;
        abort_if($contractor === null, 404);

        if (($blocker = $this->deleteBlocker()) !== null) {
            $this->addError('delete', $blocker);

            return;
        }

        if ($contractor->logo_path !== null) {
            Storage::disk('local')->delete($contractor->logo_path);
        }

        $contractor->delete();

        Flux::toast(variant: 'success', text: __('Contractor deleted.'));
        $this->redirectRoute('contractors.index', navigate: true);
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

    protected function attachmentOwner(): Note|Contractor|null
    {
        return $this->form->contractor;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-contractors';
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

            <div class="flex flex-wrap gap-2">
                @if ($form->contractor)
                    <flux:button variant="danger" icon="trash" wire:click="delete"
                        wire:confirm="{{ __('Delete this contractor? Invoices keep their copy of the data.') }}"
                        data-test="delete-contractor-button">{{ __('Delete') }}</flux:button>
                @endif
                <flux:button variant="primary" type="submit" data-test="save-contractor-button">{{ __('Save') }}</flux:button>
            </div>
        </div>

        <flux:error name="delete" />

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
                <flux:input wire:model="form.fax" :label="__('Fax')" />
                <flux:input wire:model="form.website" :label="__('Website')" />
            </div>

            <div class="flex flex-wrap items-end gap-6">
                @if ($logo)
                    <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo') }}" class="h-14 rounded bg-white p-1" />
                @elseif ($form->contractor?->logo_path)
                    <img src="{{ route('contractors.logo', $form->contractor) }}" alt="{{ __('Logo') }}" class="h-14 rounded bg-white p-1" />
                @endif

                <flux:input
                    type="file"
                    wire:model="logo"
                    :label="__('Client logo (PNG or JPG, max 2 MB)')"
                    :description="__('Shown on the client\'s own forms, e.g. the weekly time record.')"
                    accept="image/png,image/jpeg"
                />
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

        <x-attachments :owner="$form->contractor" :uploads="$uploads" :heading="__('Attached documents')" />

        <flux:card>
            <flux:textarea wire:model="form.notes" :label="__('Notes')" rows="3" />
        </flux:card>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('contractors.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
