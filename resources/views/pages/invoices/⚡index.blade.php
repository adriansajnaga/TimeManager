<?php

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\KsefSetting;
use App\Services\Invoices\InvoiceException;
use App\Services\Ksef\KsefInvoiceImporter;
use Carbon\CarbonImmutable;
use Flux\Flux;
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

    /** @var array<string, string> Faktury z KSeF, których nie udało się zapisać: numer KSeF => powód. */
    public array $importFailures = [];

    /** Zakres pobierania z KSeF. */
    public string $ksefFrom = '';

    public string $ksefTo = '';

    public function mount(): void
    {
        if (InvoiceDirection::tryFrom($this->direction) === null) {
            $this->direction = InvoiceDirection::Sales->value;
        }

        // Od ostatniego pobrania (z tygodniowym zapasem) albo od startu KSeF dla firmy (04/2026).
        $syncedUntil = KsefSetting::current()->synced_until;
        $this->ksefFrom = ($syncedUntil?->subDays(7) ?? CarbonImmutable::create(2026, 4, 1))->toDateString();
        $this->ksefTo = CarbonImmutable::today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'ksef')) {
            $this->resetPage();
        }
    }

    public function importFromKsef(KsefInvoiceImporter $importer): void
    {
        $this->authorize('manage-invoices');

        $this->validate([
            'ksefFrom' => ['required', 'date'],
            'ksefTo' => ['required', 'date', 'after_or_equal:ksefFrom', 'before_or_equal:today'],
        ]);

        @set_time_limit(300);

        try {
            $summary = $importer->import(CarbonImmutable::parse($this->ksefFrom), CarbonImmutable::parse($this->ksefTo));
        } catch (InvoiceException $exception) {
            $this->addError('ksef', $exception->getMessage());

            return;
        }

        // Zakres zapamiętujemy dopiero po pobraniu wszystkiego — przerwane pobieranie wznowi ten sam okres.
        if ($summary['stopped'] === null) {
            $settings = KsefSetting::current();
            $settings->synced_until = CarbonImmutable::parse($this->ksefTo);
            $settings->save();
        }

        Flux::modal('ksef-import')->close();
        Flux::toast(
            variant: $summary['failed'] === [] && $summary['stopped'] === null ? 'success' : 'warning',
            duration: $summary['stopped'] === null ? 5000 : 15000,
            text: __('KSeF: :sales new sales, :purchases new purchases, :confirmed confirmed, :known already here.', [
                'sales' => $summary['sales'],
                'purchases' => $summary['purchases'],
                'confirmed' => $summary['confirmed'],
                'known' => $summary['known'],
            ])
                .($summary['failed'] !== [] ? ' '.__('Could not read: :count — details below the list.', ['count' => count($summary['failed'])]) : '')
                .($summary['stopped'] !== null ? ' '.$summary['stopped'].' '.__('Still to download: :count — click “Download from KSeF” again.', ['count' => $summary['remaining']]) : ''),
        );

        $this->importFailures = $summary['failed'];

        unset($this->invoices, $this->totals);
    }

    public function ksefConfigured(): bool
    {
        return KsefSetting::current()->isConfigured();
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

        <div class="flex flex-wrap gap-2">
        @if ($this->ksefConfigured())
            <flux:modal.trigger name="ksef-import">
                <flux:button icon="cloud-arrow-down">{{ __('Download from KSeF') }}</flux:button>
            </flux:modal.trigger>
        @endif

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
    </div>

    <flux:modal name="ksef-import" class="md:w-[28rem]">
        <form wire:submit="importFromKsef" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Download from KSeF') }}</flux:heading>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="ksefFrom" type="date" :label="__('From')" />
                <flux:input wire:model="ksefTo" type="date" :label="__('To')" />
            </div>

            <flux:error name="ksef" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">{{ __('Download') }}</flux:button>
            </div>
        </form>
    </flux:modal>

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
            {{-- Zakupy: NIP sprzedawcy zamiast kwoty netto. --}}
            @if ($isSales)
                <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
            @else
                <flux:table.column>{{ __('Tax ID') }}</flux:table.column>
            @endif
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
                    @if ($isSales)
                        <flux:table.cell align="end">{{ $money($invoice->net, $invoice->currency) }}</flux:table.cell>
                    @else
                        <flux:table.cell class="whitespace-nowrap tabular-nums">{{ $invoice->counterparty_tax_id ?: '—' }}</flux:table.cell>
                    @endif
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

    @if ($importFailures !== [])
        <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Not imported from KSeF')">
            <flux:callout.text>{{ __('Run the download again — invoices skipped because of the KSeF request limit usually come in then. If a reason repeats, send it to support.') }}</flux:callout.text>
            <flux:callout.text>
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($importFailures as $number => $reason)
                        <li><strong>{{ $number }}</strong> — {{ $reason }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

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
