<?php

use App\Models\AiSetting;
use App\Models\AiUsageLog;
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

    /** Własne wskazówki dla asystenta (styl, słownictwo, nazwy). */
    public string $instructions = '';

    public bool $configured = false;

    public ?string $verifiedAt = null;

    /** Saldo konta Claude po doładowaniu (USD), wpisywane ręcznie. */
    public string $balance = '';

    public function mount(): void
    {
        $settings = AiSetting::current();

        $this->model = $settings->model;
        $this->instructions = (string) $settings->instructions;
        $this->configured = $settings->isConfigured();
        $this->verifiedAt = $settings->verified_at?->format('d.m.Y H:i');
    }

    /**
     * Saldo z console.anthropic.com (Billing) — od tej chwili koszty zapytań są od niego odejmowane.
     */
    public function saveBalance(): void
    {
        $this->authorize('manage-settings');
        $this->validate(['balance' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2']]);

        $settings = AiSetting::query()->first() ?? new AiSetting(['model' => AiSetting::DEFAULT_MODEL]);
        $settings->balance_usd = $this->balance;
        $settings->balance_set_at = now();
        $settings->save();

        $this->reset('balance');
        Flux::toast(variant: 'success', text: __('Balance saved.'));
    }

    /**
     * @return array{balance: string|null, set_at: string|null, spent: string, month_cost: string, month_count: int, estimated: string|null}
     */
    public function usage(): array
    {
        $settings = AiSetting::current();
        $month = AiUsageLog::query()->where('created_at', '>=', now()->startOfMonth());
        $estimated = $settings->estimatedBalance();

        return [
            'balance' => $settings->balance_usd,
            'set_at' => $settings->balance_set_at?->format('d.m.Y H:i'),
            'spent' => number_format($settings->spentSinceBalance()->toFloat(), 4, ',', ' '),
            'month_cost' => number_format((float) $month->clone()->sum('cost_usd'), 4, ',', ' '),
            'month_count' => $month->clone()->count(),
            'estimated' => $estimated === null ? null : number_format($estimated->toFloat(), 2, ',', ' '),
        ];
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'api_key' => ['nullable', 'string', 'max:300', 'starts_with:sk-ant-'],
            'model' => ['required', Rule::in(array_keys(AiSetting::MODELS))],
            'instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = AiSetting::query()->first() ?? new AiSetting;
        $settings->model = $this->model;
        $settings->instructions = trim($this->instructions) ?: null;

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

            <flux:textarea wire:model="instructions" rows="6" :label="__('Own instructions for the assistant')"
                :description="__('Added to every correction and translation, e.g. preferred words, how to write names of sites, tone, what to leave unchanged. The facts from the draft are always kept.')"
                :placeholder="__('e.g. Write “Unterverteilung” instead of “Verteiler”. Keep cable cross-sections in the form 5x6. Write building numbers as „Geb. 112”.')" />

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

    @php($usage = $this->usage())
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Costs and balance') }}</flux:heading>
        <flux:text>{{ __('The Claude API does not report the account balance. Enter the balance from console.anthropic.com → Billing after each top-up; the application subtracts the cost of every request (from the tokens in the response and the price list).') }}</flux:text>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <flux:text size="sm">{{ __('Estimated balance') }}</flux:text>
                <flux:heading size="xl">{{ $usage['estimated'] !== null ? '$'.$usage['estimated'] : '—' }}</flux:heading>
                @if ($usage['balance'] !== null)
                    <flux:text size="sm">{{ __(':balance entered :date, used since: $:spent', ['balance' => '$'.$usage['balance'], 'date' => $usage['set_at'], 'spent' => $usage['spent']]) }}</flux:text>
                @endif
            </div>
            <div>
                <flux:text size="sm">{{ __('This month') }}</flux:text>
                <flux:heading size="xl">${{ $usage['month_cost'] }}</flux:heading>
                <flux:text size="sm">{{ trans_choice(':count request|:count requests', $usage['month_count'], ['count' => $usage['month_count']]) }}</flux:text>
            </div>
            <form wire:submit="saveBalance" class="flex items-end gap-2">
                <flux:input wire:model="balance" :label="__('Balance after top-up (USD)')" inputmode="decimal" placeholder="5.00" />
                <flux:button type="submit">{{ __('Save') }}</flux:button>
            </form>
        </div>
    </flux:card>

    <flux:callout icon="information-circle">
        <flux:callout.text>
            {{ __('A few sentences cost a fraction of a cent per request. The text of the description is sent to Anthropic for processing.') }}
        </flux:callout.text>
    </flux:callout>
</section>
