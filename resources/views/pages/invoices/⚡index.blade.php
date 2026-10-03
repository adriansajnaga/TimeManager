<?php

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invoices')] class extends Component {
    use WithPagination;

    #[Url(except: 'sales')]
    public string $direction = 'sales';

    #[Url(except: '')]
    public string $year = '';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        if (InvoiceDirection::tryFrom($this->direction) === null) {
            $this->direction = InvoiceDirection::Sales->value;
        }
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /**
     * @return Builder<Invoice>
     */
    private function filtered(): Builder
    {
        return Invoice::query()
            ->where('direction', $this->direction)
            ->when($this->year !== '', fn ($query) => $query->whereYear('issue_date', (int) $this->year))
            ->when($this->month !== '', fn ($query) => $query->whereMonth('issue_date', (int) $this->month))
            ->when($this->status === 'unpaid', fn ($query) => $query->where('status', InvoiceStatus::Issued)->whereNull('paid_on')->where('kind', '!=', InvoiceKind::Proforma))
            ->when(in_array($this->status, ['draft', 'issued', 'cancelled'], true), fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('counterparty_name', 'like', '%'.$this->search.'%')
                    ->orWhere('counterparty_tax_id', 'like', '%'.$this->search.'%');
            }));
    }

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return $this->filtered()
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * Sumy wystawionych faktur (bez proform) w każdej walucie.
     *
     * @return Collection<int, object{currency: string, net: string, vat: string, gross: string}>
     */
    #[Computed]
    public function totals(): Collection
    {
        /** @var Collection<int, object{currency: string, net: string, vat: string, gross: string}> */
        return $this->filtered()
            ->where('status', InvoiceStatus::Issued)
            ->where('kind', '!=', InvoiceKind::Proforma)
            ->toBase()
            ->selectRaw('currency, SUM(net) as net, SUM(vat) as vat, SUM(gross) as gross')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function years(): array
    {
        $first = Invoice::query()->min('issue_date');
        $from = $first !== null ? (int) substr((string) $first, 0, 4) : (int) date('Y');

        return range((int) date('Y'), min($from, (int) date('Y')));
    }
}; ?>

@php
    $money = fn ($value, $currency) => number_format((float) $value, 2, ',', ' ').' '.$currency;
    $isSales = $direction === 'sales';
@endphp

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>
            <flux:subheading>{{ __('Sales and purchase invoices, independent of projects.') }}</flux:subheading>
        </div>

        @if ($isSales)
            <flux:dropdown position="bottom" align="end">
                <flux:button variant="primary" icon="plus" icon:trailing="chevron-down">{{ __('New invoice') }}</flux:button>
                <flux:menu>
                    @foreach (InvoiceKind::creatable() as $kind)
                        <flux:menu.item :href="route('invoices.create', ['kind' => $kind->value])" wire:navigate>{{ $kind->label() }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        @else
            <flux:button variant="primary" icon="plus" :href="route('invoices.create', ['direction' => 'purchase'])" wire:navigate>
                {{ __('New purchase invoice') }}
            </flux:button>
        @endif
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:radio.group wire:model.live="direction" variant="segmented">
            @foreach (InvoiceDirection::cases() as $case)
                <flux:radio :value="$case->value" :label="$case->label()" />
            @endforeach
        </flux:radio.group>

        <flux:select wire:model.live="year" class="max-w-32">
            <flux:select.option value="">{{ __('All years') }}</flux:select.option>
            @foreach ($this->years as $value)
                <flux:select.option :value="$value">{{ $value }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="month" class="max-w-40">
            <flux:select.option value="">{{ __('All months') }}</flux:select.option>
            @foreach (range(1, 12) as $value)
                <flux:select.option :value="$value">{{ str(\Carbon\CarbonImmutable::create(2000, $value, 1)->locale(app()->getLocale())->monthName)->ucfirst() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" class="max-w-44">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (InvoiceStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
            <flux:select.option value="unpaid">{{ __('Unpaid') }}</flux:select.option>
        </flux:select>

        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Number, contractor or tax ID')" class="max-w-xs" />
    </div>

    <flux:table :paginate="$this->invoices">
        <flux:table.columns>
            <flux:table.column>{{ __('Number') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Issue date') }}</flux:table.column>
            <flux:table.column>{{ $isSales ? __('Buyer') : __('Supplier') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Gross') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Payment') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->invoices as $invoice)
                <flux:table.row :key="$invoice->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->displayNumber() }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" color="zinc">{{ $invoice->kind->shortLabel() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ $invoice->issue_date->format('d.m.Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $invoice->counterparty_name ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $money($invoice->net, $invoice->currency) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $money($invoice->gross, $invoice->currency) }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        @if ($invoice->isPaid())
                            {{ $invoice->paid_on->format('d.m.Y') }}
                        @elseif ($invoice->isIssued() && $invoice->kind !== InvoiceKind::Proforma)
                            <flux:text size="sm" @class(['text-red-600 dark:text-red-400' => $invoice->due_date?->isPast()])>
                                {{ $invoice->due_date ? __('due :date', ['date' => $invoice->due_date->format('d.m.Y')]) : __('Unpaid') }}
                            </flux:text>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center">{{ __('No invoices found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($this->totals->isNotEmpty())
        <flux:card class="space-y-1">
            <flux:heading>{{ __('Issued invoices in the filter (without pro formas)') }}</flux:heading>
            @foreach ($this->totals as $total)
                <flux:text>
                    {{ __('Net') }}: <strong>{{ $money($total->net, $total->currency) }}</strong> ·
                    {{ __('VAT') }}: <strong>{{ $money($total->vat, $total->currency) }}</strong> ·
                    {{ __('Gross') }}: <strong>{{ $money($total->gross, $total->currency) }}</strong>
                </flux:text>
            @endforeach
        </flux:card>
    @endif
</section>
