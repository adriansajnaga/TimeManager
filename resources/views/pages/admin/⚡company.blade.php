<?php

use App\Enums\VatCode;
use App\Models\CompanySetting;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Company')] class extends Component {
    use WithFileUploads;

    public string $name = '';
    public string $street = '';
    public string $zip = '';
    public string $city = '';
    public string $country_code = 'PL';
    public string $nip = '';
    public string $vat_prefix = 'PL';
    public string $regon = '';
    public string $email = '';
    public string $phone = '';
    public string $website = '';
    public string $issue_place = '';
    public int|string $default_payment_days = 14;
    public string $default_vat_code = '23';
    public string $document_footer = '';

    public ?TemporaryUploadedFile $logo = null;

    public bool $hasLogo = false;

    public function mount(): void
    {
        $settings = CompanySetting::current();

        $this->fill([
            'name' => (string) $settings->name,
            'street' => (string) $settings->street,
            'zip' => (string) $settings->zip,
            'city' => (string) $settings->city,
            'country_code' => $settings->country_code,
            'nip' => (string) $settings->nip,
            'vat_prefix' => $settings->vat_prefix,
            'regon' => (string) $settings->regon,
            'email' => (string) $settings->email,
            'phone' => (string) $settings->phone,
            'website' => (string) $settings->website,
            'issue_place' => (string) $settings->issue_place,
            'default_payment_days' => $settings->default_payment_days,
            'default_vat_code' => $settings->default_vat_code->value,
            'document_footer' => (string) $settings->document_footer,
        ]);

        $this->hasLogo = $settings->logo_path !== null;
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'nip' => ['required', 'string', 'regex:/^\d{10}$/'],
            'vat_prefix' => ['required', 'string', 'size:2', 'alpha'],
            'regon' => ['nullable', 'string', 'regex:/^(\d{9}|\d{14})$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'issue_place' => ['nullable', 'string', 'max:255'],
            'default_payment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'default_vat_code' => ['required', Rule::enum(VatCode::class)],
            'document_footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ], [
            'nip.regex' => __('The NIP must consist of 10 digits.'),
            'regon.regex' => __('The REGON must consist of 9 or 14 digits.'),
        ]);

        $settings = CompanySetting::query()->first() ?? new CompanySetting;

        $settings->fill(collect($validated)->except('logo')->map(fn ($value) => $value === '' ? null : $value)->all());
        $settings->country_code = strtoupper($this->country_code);
        $settings->vat_prefix = strtoupper($this->vat_prefix);

        if ($this->logo !== null) {
            if ($settings->logo_path !== null) {
                Storage::disk('local')->delete($settings->logo_path);
            }

            $settings->logo_path = $this->logo->storeAs('company', 'logo.'.$this->logo->extension(), 'local');
            $this->logo = null;
        }

        $settings->save();
        $this->hasLogo = $settings->logo_path !== null;

        Flux::toast(variant: 'success', text: __('Company data saved.'));
    }
}; ?>

<section class="w-full max-w-4xl">
    <form wire:submit="save" class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ __('Company') }}</flux:heading>
                <flux:subheading>{{ __('Seller data used on documents and invoices.') }}</flux:subheading>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-company-button">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-3">
                    <flux:input wire:model="name" :label="__('Company name')" required />
                </div>
                <div class="lg:col-span-3">
                    <flux:input wire:model="street" :label="__('Street')" required />
                </div>
                <flux:input wire:model="zip" :label="__('Postal code')" required />
                <flux:input wire:model="city" :label="__('City')" required />
                <flux:input wire:model="country_code" :label="__('Country code')" maxlength="2" required />

                <div class="grid grid-cols-[7rem_1fr] gap-4 lg:col-span-2">
                    <flux:input wire:model="vat_prefix" :label="__('VAT prefix')" maxlength="2" />
                    <flux:input wire:model="nip" :label="__('NIP')" required />
                </div>
                <flux:input wire:model="regon" :label="__('REGON')" />

                <flux:input wire:model="email" :label="__('Email')" type="email" />
                <flux:input wire:model="phone" :label="__('Phone')" />
                <flux:input wire:model="website" :label="__('Website')" />
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Invoices and documents') }}</flux:heading>

            <div class="grid gap-6 lg:grid-cols-3">
                <flux:input wire:model="issue_place" :label="__('Place of issue')" />
                <flux:input wire:model="default_payment_days" :label="__('Default payment term (days)')" type="number" min="0" max="365" />

                <flux:select wire:model="default_vat_code" :label="__('Default VAT rate')">
                    @foreach (VatCode::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="lg:col-span-3">
                    <flux:input
                        wire:model="document_footer"
                        :label="__('Document footer')"
                        :description="__('Empty = company name, NIP and REGON.')"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-6">
                @if ($logo)
                    <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo') }}" class="h-16 rounded bg-white p-1" />
                @elseif ($hasLogo)
                    <img src="{{ route('admin.company.logo') }}?v={{ now()->timestamp }}" alt="{{ __('Logo') }}" class="h-16 rounded bg-white p-1" />
                @endif

                <flux:input type="file" wire:model="logo" :label="__('Logo (PNG or JPG, max 2 MB)')" accept="image/png,image/jpeg" />
            </div>
        </flux:card>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
