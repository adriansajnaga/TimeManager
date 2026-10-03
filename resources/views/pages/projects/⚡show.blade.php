<?php

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Invoice;
use App\Models\MaterialEntry;
use App\Models\Project;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project->load('contractor');
    }

    public function hours(): BigDecimal
    {
        return BigDecimal::of((string) ($this->project->timeEntries()->sum('hours') ?: '0'))->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Wartość godzin wg stawki klienta — przy ryczałcie tylko dla porównania.
     */
    public function hoursValue(): BigDecimal
    {
        return $this->hours()->multipliedBy($this->project->contractor->hourly_rate ?? '0')->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Materiał z raportów tygodniowych, zsumowany wg nazwy i jednostki.
     *
     * @return Collection<int, object{name: string, unit: string, quantity: string}>
     */
    #[Computed]
    public function materials(): Collection
    {
        return MaterialEntry::query()
            ->whereHas('weeklyReport', fn ($reports) => $reports->where('project_id', $this->project->id))
            ->get()
            ->groupBy(fn (MaterialEntry $material) => mb_strtolower($material->name).'|'.$material->unit)
            ->map(fn (Collection $group) => (object) [
                'name' => $group->first()->name,
                'unit' => $group->first()->unit,
                'quantity' => rtrim(rtrim((string) $group->reduce(fn (BigDecimal $sum, MaterialEntry $material) => $sum->plus($material->quantity), BigDecimal::zero()), '0'), '.'),
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return $this->project->invoices()->orderBy('issue_date')->get();
    }

    /**
     * Zafakturowane netto: wystawione faktury bez proform (korekty z różnicą).
     */
    public function invoicedNet(): BigDecimal
    {
        return $this->invoices
            ->filter(fn (Invoice $invoice) => $invoice->isIssued() && $invoice->kind !== InvoiceKind::Proforma)
            ->reduce(fn (BigDecimal $sum, Invoice $invoice) => $sum->plus($invoice->net), BigDecimal::zero());
    }

    public function isFixed(): bool
    {
        return $this->project->billing_type === ProjectBillingType::Fixed;
    }

    public function hasAdvances(): bool
    {
        return $this->invoices->contains(fn (Invoice $invoice) => $invoice->kind === InvoiceKind::Advance && $invoice->isIssued());
    }

    public function render()
    {
        return $this->view()->title($this->project->fullName());
    }
}; ?>

@php
    $currency = $project->contract_currency ?: $project->contractor->currency;
    $money = fn ($value, $code = null) => number_format((float) (string) $value, 2, ',', ' ').' '.($code ?? $currency);
    $contract = BigDecimal::of($project->contract_value ?? '0');
@endphp

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $project->fullName() }}</flux:heading>
                <flux:badge>{{ $project->billing_type->label() }}</flux:badge>
                <flux:badge :color="$project->status === ProjectStatus::Active ? 'green' : 'zinc'">{{ $project->status->label() }}</flux:badge>
            </div>
            <flux:subheading>
                <flux:link :href="route('projects.index')" wire:navigate>{{ __('Projects') }}</flux:link>
                · {{ $project->contractor->name }}
            </flux:subheading>
        </div>

        <flux:button icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>{{ __('Edit') }}</flux:button>
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <flux:card>
            <flux:text>{{ __('Hours logged') }}</flux:text>
            <flux:heading size="xl">{{ rtrim(rtrim(number_format((float) (string) $this->hours(), 2, ',', ' '), '0'), ',') }} h</flux:heading>
            @if ($project->contractor->hourly_rate)
                <flux:text size="sm">{{ __('At the hourly rate: :amount', ['amount' => $money($this->hoursValue(), $project->contractor->currency)]) }}</flux:text>
            @endif
        </flux:card>

        @if ($this->isFixed())
            <flux:card>
                <flux:text>{{ __('Contract value (net)') }}</flux:text>
                <flux:heading size="xl">{{ $money($contract) }}</flux:heading>
                @if (! $contract->isZero() && $project->contractor->hourly_rate)
                    @php($difference = $contract->minus($this->hoursValue()))
                    <flux:text size="sm" @class(['text-red-600 dark:text-red-400' => $difference->isNegative()])>
                        {{ $difference->isNegative() ? __('Hours exceed the lump sum by :amount', ['amount' => $money($difference->abs())]) : __('Lump sum above the hours by :amount', ['amount' => $money($difference)]) }}
                    </flux:text>
                @endif
            </flux:card>

            <flux:card>
                <flux:text>{{ __('Invoiced (net)') }}</flux:text>
                <flux:heading size="xl">{{ $money($this->invoicedNet()) }}</flux:heading>
                <flux:text size="sm">{{ __('Remaining: :amount', ['amount' => $money($contract->minus($this->invoicedNet()))]) }}</flux:text>
            </flux:card>
        @endif
    </div>

    @if ($this->isFixed())
        @can('manage-invoices')
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('Invoicing') }}</flux:heading>
                <flux:text>{{ __('Advances can be invoiced at any time; the final invoice after the project is completed (status “Closed”). Hours are informative only.') }}</flux:text>
                <div class="flex flex-wrap gap-2">
                    <flux:button icon="banknotes" :href="route('invoices.create', ['kind' => 'zal', 'project' => $project->id])" wire:navigate>
                        {{ __('Advance invoice') }}
                    </flux:button>
                    @if ($project->status === ProjectStatus::Closed)
                        <flux:button variant="primary" icon="document-check" :href="route('invoices.create', ['kind' => $this->hasAdvances() ? 'roz' : 'vat', 'project' => $project->id])" wire:navigate>
                            {{ __('Final invoice') }}
                        </flux:button>
                    @else
                        <flux:button icon="document-check" disabled>{{ __('Final invoice') }}</flux:button>
                        <flux:text size="sm" class="self-center">{{ __('Close the project first.') }}</flux:text>
                    @endif
                </div>
            </flux:card>
        @endcan
    @endif

    @if ($this->invoices->isNotEmpty())
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Invoices') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Number') }}</flux:table.column>
                    <flux:table.column>{{ __('Type') }}</flux:table.column>
                    <flux:table.column>{{ __('Issue date') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Net') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Gross') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->invoices as $invoice)
                        <flux:table.row :key="$invoice->id">
                            <flux:table.cell><flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->displayNumber() }}</flux:link></flux:table.cell>
                            <flux:table.cell>{{ $invoice->kind->shortLabel() }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->issue_date->format('d.m.Y') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($invoice->net, $invoice->currency) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $money($invoice->gross, $invoice->currency) }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <flux:card class="space-y-3">
        <flux:heading size="lg">{{ __('Material') }}</flux:heading>
        @forelse ($this->materials as $material)
            <flux:text>{{ $material->name }} — {{ $material->quantity }} {{ $material->unit }}</flux:text>
        @empty
            <flux:text>{{ __('No material recorded in the weekly reports.') }}</flux:text>
        @endforelse
    </flux:card>
</section>
