<?php

use App\Models\BankAccount;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank accounts')] class extends Component {
    public ?int $editingId = null;

    public string $label = '';
    public string $iban = '';
    public string $swift = '';
    public string $currency = 'PLN';
    public bool $is_default = false;

    /**
     * @return Collection<int, BankAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return BankAccount::query()->orderByDesc('is_default')->orderBy('label')->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'label', 'iban', 'swift', 'currency', 'is_default');
        $this->resetValidation();
        $this->is_default = $this->accounts->isEmpty();

        Flux::modal('bank-account')->show();
    }

    public function edit(BankAccount $account): void
    {
        $this->resetValidation();
        $this->editingId = $account->id;
        $this->fill($account->only('label', 'iban', 'swift', 'currency', 'is_default'));
        $this->swift = (string) $account->swift;

        Flux::modal('bank-account')->show();
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'iban' => ['required', 'string', 'max:50'],
            'swift' => ['nullable', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'is_default' => ['boolean'],
        ]);

        $account = $this->editingId ? BankAccount::query()->findOrFail($this->editingId) : new BankAccount;

        $account->fill([
            'label' => trim($this->label),
            'iban' => strtoupper(trim($this->iban)),
            'swift' => trim($this->swift) === '' ? null : strtoupper(trim($this->swift)),
            'currency' => strtoupper($this->currency),
            'is_default' => $this->is_default,
        ])->save();

        unset($this->accounts);
        Flux::modal('bank-account')->close();
        Flux::toast(variant: 'success', text: __('Bank account saved.'));
    }

    public function delete(BankAccount $account): void
    {
        $this->authorize('manage-settings');

        $account->delete();
        unset($this->accounts);
    }
}; ?>

<section class="w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank accounts') }}</flux:heading>
            <flux:subheading>{{ __('Accounts shown on invoices. The default one is used when a client has none assigned.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New account') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Label') }}</flux:table.column>
            <flux:table.column>{{ __('IBAN') }}</flux:table.column>
            <flux:table.column>{{ __('SWIFT/BIC') }}</flux:table.column>
            <flux:table.column>{{ __('Currency') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->accounts as $account)
                <flux:table.row :key="$account->id">
                    <flux:table.cell variant="strong">
                        {{ $account->label }}
                        @if ($account->is_default)
                            <flux:badge size="sm" color="green" class="ms-2">{{ __('Default') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $account->iban }}</flux:table.cell>
                    <flux:table.cell>{{ $account->swift ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $account->currency }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $account->id }})" :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this bank account?') }}" :aria-label="__('Delete')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">{{ __('No bank accounts yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="bank-account" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit bank account') : __('New bank account') }}</flux:heading>

            <flux:input wire:model="label" :label="__('Label')" :description="__('Shown before the number on the invoice, e.g. bank name.')" required />
            <flux:input wire:model="iban" :label="__('IBAN')" required />

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="swift" :label="__('SWIFT/BIC')" />
                <flux:input wire:model="currency" :label="__('Currency')" maxlength="3" required />
            </div>

            <flux:switch wire:model="is_default" :label="__('Default account')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
