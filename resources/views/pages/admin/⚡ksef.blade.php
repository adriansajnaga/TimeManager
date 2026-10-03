<?php

use App\Enums\KsefEnvironment;
use App\Models\KsefSetting;
use App\Services\Invoices\InvoiceException;
use App\Services\Ksef\KsefClient;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('KSeF')] class extends Component {
    public string $environment = 'test';

    public string $nip = '';

    /** Nowy token; puste pole = zostaw zapisany. */
    public string $token = '';

    public bool $configured = false;

    public ?string $verifiedAt = null;

    public function mount(): void
    {
        $this->fillFrom(KsefSetting::current());
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'environment' => ['required', Rule::enum(KsefEnvironment::class)],
            'nip' => ['required', 'string', 'regex:/^\d{10}$/'],
            'token' => ['nullable', 'string', 'max:1000'],
        ]);

        $settings = KsefSetting::query()->first() ?? new KsefSetting;
        $changed = $settings->environment?->value !== $this->environment || $settings->nip !== $this->nip;

        $settings->environment = KsefEnvironment::from($this->environment);
        $settings->nip = $this->nip;

        if ($this->token !== '') {
            $settings->token = trim($this->token);
            $changed = true;
        }

        if ($changed) {
            $settings->verified_at = null;
        }

        $settings->save();
        KsefClient::forCurrentSettings()->forgetAccessToken();

        $this->reset('token');
        $this->fillFrom($settings);

        Flux::toast(variant: 'success', text: __('KSeF settings saved.'));
    }

    public function test(): void
    {
        $this->authorize('manage-settings');

        $client = KsefClient::forCurrentSettings();
        $client->forgetAccessToken();

        try {
            $client->currentSession();
        } catch (InvoiceException $exception) {
            $this->addError('test', $exception->getMessage());

            return;
        }

        $settings = KsefSetting::current();
        $settings->verified_at = now();
        $settings->save();
        $this->fillFrom($settings);

        Flux::toast(variant: 'success', text: __('Connection to KSeF works.'));
    }

    public function removeToken(): void
    {
        $this->authorize('manage-settings');

        KsefSetting::query()->first()?->update(['token' => null, 'verified_at' => null]);
        $this->fillFrom(KsefSetting::current());
    }

    private function fillFrom(KsefSetting $settings): void
    {
        $this->environment = $settings->environment->value;
        $this->nip = (string) $settings->nip;
        $this->configured = $settings->isConfigured();
        $this->verifiedAt = $settings->verified_at?->format('d.m.Y H:i');
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('KSeF') }}</flux:heading>
        <flux:subheading>{{ __('National e-Invoice System: invoice numbers, sending and downloading sales and purchase invoices.') }}</flux:subheading>
    </div>

    <flux:card class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            @if ($configured)
                <flux:badge color="green" icon="check-circle">{{ __('Token saved') }}</flux:badge>
                @if ($verifiedAt)
                    <flux:text>{{ __('Last successful test: :date', ['date' => $verifiedAt]) }}</flux:text>
                @endif
            @else
                <flux:badge color="zinc">{{ __('No token') }}</flux:badge>
            @endif

            <flux:badge :color="$environment === 'prod' ? 'red' : 'blue'">{{ KsefEnvironment::from($environment)->label() }}</flux:badge>
        </div>

        <form wire:submit="save" class="space-y-6">
            <flux:select wire:model.live="environment" :label="__('Environment')">
                @foreach (KsefEnvironment::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($environment === 'prod')
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ __('In production every issued invoice is legally binding and goes to the tax office. Test the workflow in the test environment first.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:input wire:model="nip" :label="__('Seller NIP')" maxlength="10" />

            <flux:input
                wire:model="token"
                type="password"
                viewable
                autocomplete="off"
                :label="__('KSeF token')"
                :placeholder="$configured ? __('Leave empty to keep the saved token') : ''"
                :description="__('Generate the token in the KSeF taxpayer application (permissions: issuing and viewing invoices). It is stored encrypted with the application key (APP_KEY).')"
            />

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                @if ($configured)
                    <flux:button icon="signal" wire:click="test" wire:loading.attr="disabled">{{ __('Test connection') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" wire:click="removeToken" wire:confirm="{{ __('Remove the saved KSeF token?') }}">{{ __('Remove token') }}</flux:button>
                @endif
            </div>

            <flux:error name="test" />
        </form>
    </flux:card>
</section>
