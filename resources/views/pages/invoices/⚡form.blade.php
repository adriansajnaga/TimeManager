<?php

use App\Enums\ContractorType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLanguage;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\VatCode;
use App\Livewire\Forms\InvoiceForm;
use App\Models\BankAccount;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Project;
use App\Enums\ProjectBillingType;
use App\Services\Invoices\InvoiceException;
use App\Services\Invoices\NbpExchangeRates;
use App\Services\Invoices\VatSummary;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public InvoiceForm $form;

    public function mount(?Invoice $invoice = null): void
    {
        if ($invoice?->exists) {
            abort_unless($invoice->isEditable(), 403);
            $this->form->setInvoice($invoice);

            return;
        }

        $request = request();

        if ($request->integer('correct') > 0) {
            $original = Invoice::query()->with(['items', 'contractor'])->findOrFail($request->integer('correct'));
            abort_unless($original->isSales() && $original->isIssued() && $original->kind !== InvoiceKind::Proforma, 404);
            $this->form->setCorrectionOf($original);

            return;
        }

        if ($request->integer('copy') > 0) {
            $this->form->setCopyOf(Invoice::query()->with(['items', 'contractor'])->findOrFail($request->integer('copy')));

            return;
        }

        $direction = InvoiceDirection::tryFrom((string) $request->query('direction')) ?? InvoiceDirection::Sales;
        $kind = InvoiceKind::tryFrom((string) $request->query('kind')) ?? InvoiceKind::Vat;

        // Korekta sprzedaży powstaje tylko z wystawionej faktury.
        if ($direction === InvoiceDirection::Sales && $kind === InvoiceKind::Correction) {
            $kind = InvoiceKind::Vat;
        }

        $this->form->setInvoice(null, $direction, $kind);

        if ($request->integer('project') > 0) {
            $this->form->applyProject(Project::query()->with('contractor')->findOrFail($request->integer('project')), $kind);
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'form.contractor_id') {
            $this->form->applyContractor();
            $this->form->advance_ids = [];
            $this->form->project_id = null;
        }

        if ($property === 'form.currency') {
            $this->form->currency = strtoupper($this->form->currency);
            $this->form->advance_ids = [];
        }
    }

    public function addItem(): void
    {
        $this->form->addItem();
    }

    public function removeItem(int $index): void
    {
        $this->form->removeItem($index);
    }

    public function fetchExchangeRate(NbpExchangeRates $rates): void
    {
        $this->resetErrorBag('form.exchange_rate');

        $date = $this->form->sale_date ?: $this->form->issue_date;

        try {
            $rate = $rates->before($this->form->currency, CarbonImmutable::parse($date));
        } catch (InvoiceException $exception) {
            $this->addError('form.exchange_rate', $exception->getMessage());

            return;
        }

        $this->form->exchange_rate = $rate->rate;
        $this->form->exchange_rate_date = $rate->effective_date->toDateString();
        $this->form->exchange_rate_table = $rate->table_number;
    }

    public function save(): void
    {
        $this->authorize('manage-invoices');

        $invoice = $this->form->save();

        Flux::toast(variant: 'success', text: __('Invoice saved.'));

        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }

    public function isPurchase(): bool
    {
        return $this->form->direction === InvoiceDirection::Purchase->value;
    }

    /**
     * Rodzaje do wyboru: sprzedaż — bez korekty (ta powstaje z faktury), zakup — VAT i korekta.
     *
     * @return list<InvoiceKind>
     */
    public function kinds(): array
    {
        if ($this->form->kind === InvoiceKind::Correction->value && ! $this->isPurchase()) {
            return [InvoiceKind::Correction];
        }

        return $this->isPurchase() ? [InvoiceKind::Vat, InvoiceKind::Correction] : InvoiceKind::creatable();
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function contractors(): Collection
    {
        $types = $this->isPurchase()
            ? [ContractorType::Supplier, ContractorType::Both]
            : [ContractorType::Client, ContractorType::Both];

        return Contractor::query()
            ->where(fn ($query) => $query->whereIn('type', $types)->where('is_active', true))
            ->when($this->form->contractor_id, fn ($query) => $query->orWhere('id', $this->form->contractor_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Projekty ryczałtowe nabywcy (do powiązania zaliczek i faktury końcowej).
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        if (! $this->form->contractor_id || $this->isPurchase()) {
            return new Collection;
        }

        return Project::query()
            ->where('contractor_id', $this->form->contractor_id)
            ->where(fn ($query) => $query->where('billing_type', ProjectBillingType::Fixed)->when($this->form->project_id, fn ($query) => $query->orWhere('id', $this->form->project_id)))
            ->orderBy('number')
            ->get();
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
     * Zaliczkowe nabywcy w walucie faktury, jeszcze nierozliczone inną fakturą rozliczeniową.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function advanceCandidates(): Collection
    {
        if (! $this->form->contractor_id) {
            return new Collection;
        }

        return Invoice::query()
            ->sales()
            ->where('kind', InvoiceKind::Advance)
            ->where('status', InvoiceStatus::Issued)
            ->where('contractor_id', $this->form->contractor_id)
            ->where('currency', strtoupper($this->form->currency))
            ->whereDoesntHave('settlements', fn ($query) => $query
                ->where('status', '!=', InvoiceStatus::Cancelled)
                ->when($this->form->invoice, fn ($query) => $query->whereKeyNot($this->form->invoice->id)))
            ->orderBy('issue_date')
            ->get();
    }

    public function summary(): VatSummary
    {
        return $this->form->summary();
    }

    public function render()
    {
        return $this->view()->title($this->form->invoice?->displayNumber() ?? __('New invoice'));
    }
}; ?>

@php
    $kind = InvoiceKind::tryFrom($form->kind);
    $summary = $this->summary();
    $money = fn ($value) => number_format((float) (string) $value, 2, ',', ' ').' '.$form->currency;
@endphp

<section class="w-full max-w-6xl">
    <form wire:submit="save" class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">
                    {{ $form->invoice ? $form->invoice->displayNumber() : ($this->isPurchase() ? __('New purchase invoice') : __('New invoice')) }}
                </flux:heading>
                <flux:subheading>
                    <flux:link :href="route('invoices.index', ['direction' => $form->direction])" wire:navigate>
                        {{ $this->isPurchase() ? __('Purchase invoices') : __('Sales invoices') }}
                    </flux:link>
                </flux:subheading>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-invoice-button">
                {{ $this->isPurchase() ? __('Save') : __('Save draft') }}
            </flux:button>
        </div>

        {{-- Nagłówek --}}
        <flux:card class="space-y-6">
            <div class="grid gap-6 lg:grid-cols-3">
                <flux:select wire:model.live="form.kind" :label="__('Invoice type')" :disabled="count($this->kinds()) === 1">
                    @foreach ($this->kinds() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="lg:col-span-2">
                    <flux:select wire:model.live="form.contractor_id" :label="$this->isPurchase() ? __('Supplier') : __('Buyer')" required>
                        <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                        @foreach ($this->contractors as $contractor)
                            <flux:select.option :value="$contractor->id">{{ $contractor->name }}{{ $contractor->vatId() ? ' ('.$contractor->vatId().')' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->isPurchase())
                    <flux:input wire:model="form.number" :label="__('Invoice number')" required />
                @endif

                @if ($this->projects->isNotEmpty())
                    <flux:select wire:model="form.project_id" :label="__('Fixed-price project')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach ($this->projects as $project)
                            <flux:select.option :value="$project->id">{{ $project->fullName() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model.live.blur="form.issue_date" type="date" :label="__('Issue date')" required />
                <flux:input wire:model="form.sale_date" type="date" :label="__('Sale / completion date')" />
                <flux:input wire:model="form.due_date" type="date" :label="__('Payment due')" />

                <flux:select wire:model="form.payment_method" :label="__('Payment method')">
                    @foreach (PaymentMethod::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @unless ($this->isPurchase())
                    <div class="lg:col-span-2">
                        <flux:select wire:model="form.bank_account_id" :label="__('Bank account')">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach ($this->bankAccounts as $account)
                                <flux:select.option :value="$account->id">{{ $account->displayName() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:select wire:model="form.language" :label="__('Invoice language')">
                        @foreach (InvoiceLanguage::cases() as $case)
                            <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="form.issue_place" :label="__('Place of issue')" />
                @endunless

                <flux:input wire:model="form.paid_on" type="date" :label="__('Paid on')" />
            </div>

            <div class="grid items-end gap-6 lg:grid-cols-4">
                <flux:input wire:model.live.blur="form.currency" :label="__('Currency')" maxlength="3" />

                @if (strtoupper($form->currency) !== 'PLN')
                    <flux:input wire:model="form.exchange_rate" :label="__('Exchange rate (PLN)')" inputmode="decimal" />
                    <flux:input wire:model="form.exchange_rate_date" type="date" :label="__('Rate date')" />
                    <div class="space-y-1">
                        <flux:button wire:click="fetchExchangeRate" icon="arrow-path" class="w-full">{{ __('NBP rate') }}</flux:button>
                        @if ($form->exchange_rate_table)
                            <flux:text size="sm">{{ __('Table :table', ['table' => $form->exchange_rate_table]) }}</flux:text>
                        @endif
                    </div>
                @endif
            </div>

            @if (strtoupper($form->currency) !== 'PLN')
                <flux:text size="sm">
                    {{ __('Average NBP rate from the last business day before the sale date (or issue date). Required when the invoice shows VAT; for reverse charge it is informative.') }}
                </flux:text>
            @endif
        </flux:card>

        {{-- Korekta --}}
        @if ($kind === InvoiceKind::Correction)
            <flux:card class="space-y-6">
                <flux:heading size="lg">{{ __('Corrected invoice') }}</flux:heading>

                <div class="grid gap-6 lg:grid-cols-3">
                    <flux:input wire:model="form.corrected_number" :label="__('Invoice number')" :readonly="$form->corrected_invoice_id !== null" required />
                    <flux:input wire:model="form.corrected_issue_date" type="date" :label="__('Issue date')" :readonly="$form->corrected_invoice_id !== null" required />
                    <flux:input wire:model="form.corrected_ksef_number" :label="__('KSeF number')" />

                    <div class="lg:col-span-3">
                        <flux:input wire:model="form.correction_reason" :label="__('Reason for correction')" required />
                    </div>
                </div>

                @if ($form->before_items !== [])
                    <div>
                        <flux:heading>{{ __('Before correction') }}</flux:heading>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Description') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                                <flux:table.column>{{ __('Unit') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Unit price') }}</flux:table.column>
                                <flux:table.column>{{ __('VAT') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($form->before_items as $item)
                                    <flux:table.row :key="'before-'.$loop->index">
                                        <flux:table.cell class="whitespace-pre-line">{{ $item['name'] }}</flux:table.cell>
                                        <flux:table.cell align="end">{{ $item['quantity'] }}</flux:table.cell>
                                        <flux:table.cell>{{ $item['unit'] }}</flux:table.cell>
                                        <flux:table.cell align="end">{{ $item['unit_price'] }}</flux:table.cell>
                                        <flux:table.cell>{{ VatCode::tryFrom($item['vat_code'])?->shortLabel() }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif
            </flux:card>
        @endif

        {{-- Pozycje --}}
        <flux:card class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <flux:heading size="lg">
                    @if ($kind === InvoiceKind::Correction)
                        {{ __('After correction') }}
                    @elseif ($kind === InvoiceKind::Advance || $kind === InvoiceKind::Final)
                        {{ __('Order items') }}
                    @else
                        {{ __('Items') }}
                    @endif
                </flux:heading>

                <flux:button size="sm" icon="plus" wire:click="addItem">{{ __('Add item') }}</flux:button>
            </div>

            <div class="space-y-4">
                @foreach ($form->items as $index => $item)
                    <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 lg:grid-cols-[1fr_6rem_6rem_9rem_11rem_auto] dark:border-zinc-700" wire:key="item-{{ $index }}">
                        <flux:textarea wire:model="form.items.{{ $index }}.name" :label="__('Description')" rows="2" resize="vertical" />
                        <flux:input wire:model.live.blur="form.items.{{ $index }}.quantity" :label="__('Quantity')" inputmode="decimal" />
                        <flux:input wire:model="form.items.{{ $index }}.unit" :label="__('Unit')" />
                        <flux:input wire:model.live.blur="form.items.{{ $index }}.unit_price" :label="__('Unit price')" inputmode="decimal" />
                        <flux:select wire:model.live="form.items.{{ $index }}.vat_code" :label="__('VAT rate')">
                            @foreach (VatCode::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->shortLabel() }} — {{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="self-end">
                            <flux:button variant="ghost" icon="trash" wire:click="removeItem({{ $index }})" :aria-label="__('Remove')" :disabled="count($form->items) === 1" />
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="form.items" />

            @if (collect($form->items)->contains('vat_code', VatCode::Exempt->value))
                <flux:input wire:model="form.vat_exemption_basis" :label="__('Legal basis of the VAT exemption')" :placeholder="__('e.g. art. 113 ust. 1 ustawy o VAT')" required />
            @endif

            @if ($kind === InvoiceKind::Advance)
                <div class="grid gap-6 lg:grid-cols-3">
                    <flux:input wire:model.live.blur="form.advance_amount" :label="__('Advance received (gross)')" inputmode="decimal" required />
                    <div class="lg:col-span-2 self-end">
                        <flux:text>{{ __('Order value (gross)') }}: <strong>{{ $money($this->form->orderSummary()->gross()) }}</strong></flux:text>
                    </div>
                </div>
            @endif

            @if ($kind === InvoiceKind::Final)
                <flux:checkbox.group wire:model.live="form.advance_ids" :label="__('Advance invoices settled by this invoice')">
                    @forelse ($this->advanceCandidates as $advance)
                        <flux:checkbox :value="(string) $advance->id" :label="$advance->number.' — '.$advance->issue_date->format('d.m.Y').' — '.$money($advance->gross)" />
                    @empty
                        <flux:text>{{ __('This buyer has no issued advance invoices in this currency.') }}</flux:text>
                    @endforelse
                </flux:checkbox.group>
            @endif
        </flux:card>

        {{-- Zestawienie VAT --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg">
                @if ($kind === InvoiceKind::Correction)
                    {{ __('Difference') }}
                @elseif ($kind === InvoiceKind::Advance)
                    {{ __('Advance') }}
                @elseif ($kind === InvoiceKind::Final)
                    {{ __('Remaining to pay') }}
                @else
                    {{ __('Summary') }}
                @endif
            </flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('VAT rate') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('VAT') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Gross') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($summary->rows() as $row)
                        <flux:table.row :key="'vat-'.$row['code']->value">
                            <flux:table.cell>{{ $row['code']->label() }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['net']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['vat']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($row['gross']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                    <flux:table.row>
                        <flux:table.cell variant="strong">{{ __('Total') }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong">{{ $money($summary->net()) }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong">{{ $money($summary->vat()) }}</flux:table.cell>
                        <flux:table.cell align="end" variant="strong">{{ $money($summary->gross()) }}</flux:table.cell>
                    </flux:table.row>
                </flux:table.rows>
            </flux:table>

            @if ($summary->hasReverseCharge())
                <flux:callout icon="information-circle" color="blue" :heading="__('Reverse charge')">
                    <flux:callout.text>{{ __('The invoice will carry the annotation “odwrotne obciążenie / reverse charge”; the buyer settles the VAT.') }}</flux:callout.text>
                </flux:callout>
            @endif
        </flux:card>

        <flux:card>
            <flux:textarea wire:model="form.notes" :label="__('Notes on the invoice')" rows="3" />
        </flux:card>
    </form>
</section>
