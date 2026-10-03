<?php

use App\Models\AiSetting;
use App\Services\Ai\AiException;
use App\Services\Ai\TextAssistant;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI assistant')] class extends Component {
    /** Nowy klucz API; puste pole = zostaw zapisany. */
    public string $api_key = '';

    public string $model = AiSetting::DEFAULT_MODEL;

    public bool $configured = false;

    public ?string $verifiedAt = null;

    public function mount(): void
    {
        $settings = AiSetting::current();

        $this->model = $settings->model;
        $this->configured = $settings->isConfigured();
        $this->verifiedAt = $settings->verified_at?->format('d.m.Y H:i');
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'api_key' => ['nullable', 'string', 'max:300', 'starts_with:sk-ant-'],
            'model' => ['required', Rule::in(array_keys(AiSetting::MODELS))],
        ]);

        $settings = AiSetting::query()->first() ?? new AiSetting;
        $settings->model = $this->model;

        if ($this->api_key !== '') {
            $settings->api_key = trim($this->api_key);
            $settings->verified_at = null;
        }

        $settings->save();

        $this->reset('api_key');
        $this->configured = $settings->isConfigured();
        $this->verifiedAt = $settings->verified_at?->format('d.m.Y H:i');

        Flux::toast(variant: 'success', text: __('AI settings saved.'));
    }

    public function test(): void
    {
        $this->authorize('manage-settings');

        try {
            app(TextAssistant::class)->ping();
        } catch (AiException $exception) {
            $this->addError('test', $exception->getMessage());

            return;
        }

        $settings = AiSetting::current();
        $settings->verified_at = now();
        $settings->save();
        $this->verifiedAt = $settings->verified_at->format('d.m.Y H:i');

        Flux::toast(variant: 'success', text: __('Connection works.'));
    }

    public function removeKey(): void
    {
        $this->authorize('manage-settings');

        AiSetting::query()->first()?->update(['api_key' => null]);
        $this->configured = false;
        $this->verifiedAt = null;
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('AI assistant') }}</flux:heading>
        <flux:subheading>{{ __('Corrects and translates work descriptions in weekly reports (Claude API by Anthropic).') }}</flux:subheading>
    </div>

    <flux:card class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            @if ($configured)
                <flux:badge color="green" icon="check-circle">{{ __('API key saved') }}</flux:badge>
                @if ($verifiedAt)
                    <flux:text>{{ __('Last successful test: :date', ['date' => $verifiedAt]) }}</flux:text>
                @endif
            @else
                <flux:badge color="zinc">{{ __('No API key') }}</flux:badge>
            @endif
        </div>

        <form wire:submit="save" class="space-y-6">
            <flux:input
                wire:model="api_key"
                type="password"
                viewable
                autocomplete="off"
                :label="__('API key')"
                :placeholder="$configured ? __('Leave empty to keep the saved key') : 'sk-ant-…'"
                :description="__('Create the key at console.anthropic.com → API Keys. It is stored encrypted with the application key (APP_KEY).')"
            />

            <flux:select wire:model="model" :label="__('Model')">
                @foreach (AiSetting::MODELS as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                @if ($configured)
                    <flux:button icon="signal" wire:click="test" wire:loading.attr="disabled">{{ __('Test connection') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" wire:click="removeKey" wire:confirm="{{ __('Remove the saved API key?') }}">{{ __('Remove key') }}</flux:button>
                @endif
            </div>

            <flux:error name="test" />
        </form>
    </flux:card>

    <flux:callout icon="information-circle">
        <flux:callout.text>
            {{ __('A few sentences cost a fraction of a cent per request. The text of the description is sent to Anthropic for processing.') }}
        </flux:callout.text>
    </flux:callout>
</section>
