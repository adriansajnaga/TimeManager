<?php

use App\Enums\ContractorType;
use App\Models\Contractor;
use App\Models\Settlement;
use App\Models\WorkWeek;
use App\Services\Invoices\InvoiceException;
use App\Services\Mileage\MileageTrip;
use App\Services\Settlements\SettlementPreview;
use App\Services\Settlements\SettlementService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Settlements')] class extends Component {
    #[Url(as: 'client', except: '')]
    public string $contractorId = '';

    /** @var list<string> */
    public array $weekIds = [];

    public function mount(): void
    {
        if ($this->contractorId === '') {
            $this->contractorId = (string) ($this->clients->first()?->id ?? '');
        }

        $this->selectAll();
    }

    public function updatedContractorId(): void
    {
        unset($this->billableWeeks, $this->preview, $this->history);
        $this->selectAll();
    }

    public function selectAll(): void
    {
        $this->weekIds = $this->billableWeeks->map(fn (WorkWeek $week) => (string) $week->id)->values()->all();
    }

    public function createInvoice(SettlementService $service): void
    {
        $this->authorize('manage-settlements');
        $this->authorize('manage-invoices');

        $contractor = $this->contractor;
        abort_if($contractor === null, 404);

        try {
            $settlement = $service->createInvoice($contractor, $this->selectedWeeks(), auth()->user());
        } catch (InvoiceException $exception) {
            $this->addError('settlement', $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft invoice created from the settlement.'));
        $this->redirectRoute('invoices.show', $settlement->invoice_id, navigate: true);
    }

    /**
     * Klienci z godzinami (aktywni).
     *
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Contractor::query()
            ->whereIn('type', [ContractorType::Client, ContractorType::Both])
            ->where('is_active', true)
            ->whereHas('projects.timeEntries')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function contractor(): ?Contractor
    {
        return $this->contractorId !== '' ? Contractor::query()->find($this->contractorId) : null;
    }

    /**
     * @return Collection<int, WorkWeek>
     */
    #[Computed]
    public function billableWeeks(): Collection
    {
        return $this->contractor !== null ? app(SettlementService::class)->billableWeeks($this->contractor) : new Collection;
    }

    #[Computed]
    public function preview(): ?SettlementPreview
    {
        return $this->contractor !== null ? app(SettlementService::class)->preview($this->contractor, $this->selectedWeeks()) : null;
    }

    /**
     * @return Collection<int, Settlement>
     */
    #[Computed]
    public function history(): Collection
    {
        return Settlement::query()
            ->with(['invoice', 'workWeeks'])
            ->when($this->contractor !== null, fn ($query) => $query->where('contractor_id', $this->contractor?->id))
            ->latest('period_to')
            ->limit(20)
            ->get();
    }

    /**
     * Godziny klienta w części tygodnia (do listy).
     */
    public function hoursIn(WorkWeek $week): string
    {
        return (string) $week->timeEntries()
            ->whereHas('project', fn ($projects) => $projects->where('contractor_id', $this->contractor?->id))
            ->sum('hours');
    }

    public function updatedWeekIds(): void
    {
        unset($this->preview);
    }

    /**
     * @return Collection<int, WorkWeek>
     */
    private function selectedWeeks(): Collection
    {
        return $this->billableWeeks->whereIn('id', array_map('intval', $this->weekIds))->values();
    }
}; ?>

@php
    $preview = $this->preview;
    $currency = $this->contractor?->currency ?? 'PLN';
    $money = fn ($value) => number_format((float) (string) $value, 2, ',', ' ').' '.$currency;
    $number = fn ($value) => rtrim(rtrim(number_format((float) (string) $value, 2, ',', ' '), '0'), ',');
@endphp

<section class="w-full max-w-5xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Settlements') }}</flux:heading>
        <flux:subheading>{{ __('Closed weeks of hourly projects → hours × rate + mileage → draft invoice and document package.') }}</flux:subheading>
    </div>

    <flux:select wire:model.live="contractorId" :label="__('Client')" class="max-w-md">
        @foreach ($this->clients as $client)
            <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->contractor)
        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Closed weeks to settle') }}</flux:heading>
                @if ($this->billableWeeks->isNotEmpty())
                    <flux:button size="sm" variant="ghost" wire:click="selectAll">{{ __('Select all') }}</flux:button>
                @endif
            </div>

            @if ($this->billableWeeks->isEmpty())
                <flux:text>{{ __('No closed, unsettled weeks with hours of this client.') }}</flux:text>
            @else
                <flux:checkbox.group wire:model.live="weekIds">
                    @foreach ($this->billableWeeks as $week)
                        <flux:checkbox :value="(string) $week->id" :label="$week->label().' — '.$number($this->hoursIn($week)).' h'" />
                    @endforeach
                </flux:checkbox.group>
            @endif
        </flux:card>

        @if ($preview && $preview->weeks->isNotEmpty())
            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('Settlement') }}</flux:heading>

                <flux:table>
                    <flux:table.rows>
                        <flux:table.row>
                            <flux:table.cell>{{ __('Hours') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $number($preview->hours) }} h × {{ $money($preview->hourlyRate) }}</flux:table.cell>
                            <flux:table.cell align="end" variant="strong">{{ $money($preview->hoursAmount()) }}</flux:table.cell>
                        </flux:table.row>
                        <flux:table.row>
                            <flux:table.cell>{{ __('Mileage') }} ({{ count($preview->trips) }} {{ __('days') }})</flux:table.cell>
                            <flux:table.cell align="end">{{ MileageTrip::number($preview->km) }} km × {{ rtrim(rtrim(number_format((float) (string) $preview->kmRate, 4, ',', ' '), '0'), ',') }} {{ $currency }}</flux:table.cell>
                            <flux:table.cell align="end" variant="strong">{{ $money($preview->kmAmount()) }}</flux:table.cell>
                        </flux:table.row>
                        <flux:table.row>
                            <flux:table.cell variant="strong">{{ __('Total') }}</flux:table.cell>
                            <flux:table.cell></flux:table.cell>
                            <flux:table.cell align="end" variant="strong">{{ $money($preview->total()) }}</flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>

                <div>
                    <flux:text size="sm">{{ __('Invoice line') }} ({{ $this->contractor->invoice_line_mode->label() }}):</flux:text>
                    <pre class="mt-1 whitespace-pre-wrap rounded bg-zinc-50 p-3 text-sm dark:bg-zinc-800">{{ app(SettlementService::class)->description($this->contractor, $preview) }}</pre>
                </div>

                @if ($preview->materials->isNotEmpty())
                    <flux:callout icon="cube" color="blue" :heading="__('Material in these weeks')">
                        <flux:callout.text>
                            {{ $preview->materials->map(fn ($material) => $material->name.' '.$material->quantityLabel().' '.$material->unit)->implode(', ') }}.
                            {{ __('Material is billed separately — add priced lines to the draft invoice if needed.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @if ($preview->problems() !== [])
                    <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Missing before settling')">
                        <flux:callout.text>
                            <ul class="list-disc ps-5">
                                @foreach ($preview->problems() as $problem)
                                    <li>{{ $problem }}</li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="settlement" />

                <div class="flex flex-wrap gap-2">
                    <flux:button icon="document-text" target="_blank" :href="route('documents.stundenzettel', ['client' => $this->contractor->id, 'weeks' => $preview->weeks->pluck('id')->all()])">
                        {{ __('Timesheet (PDF)') }}
                    </flux:button>
                    <flux:button icon="document-duplicate" target="_blank" :href="route('documents.reports', ['client' => $this->contractor->id, 'weeks' => $preview->weeks->pluck('id')->all()])">
                        {{ __('Weekly reports (PDF)') }}
                    </flux:button>
                    @if ($preview->trips !== [])
                        <flux:button icon="truck" target="_blank" :href="route('documents.mileage', ['client' => $this->contractor->id, 'weeks' => $preview->weeks->pluck('id')->all()])">
                            {{ __('Mileage (PDF)') }}
                        </flux:button>
                    @endif
                    <flux:button variant="primary" icon="document-plus" wire:click="createInvoice" :disabled="$preview->problems() !== []" data-test="create-settlement-invoice">
                        {{ __('Create draft invoice') }}
                    </flux:button>
                </div>
            </flux:card>
        @endif
    @endif

    @if ($this->history->isNotEmpty())
        <flux:card class="space-y-3">
            <flux:heading size="lg">{{ __('Previous settlements') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Period') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Hours') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('km') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->history as $settlement)
                        <flux:table.row :key="$settlement->id">
                            <flux:table.cell>{{ $settlement->period_from->format('d.m.Y') }} – {{ $settlement->period_to->format('d.m.Y') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $number($settlement->hours) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $number($settlement->km) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format((float) $settlement->amount, 2, ',', ' ') }} {{ $settlement->currency }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($settlement->invoice)
                                    <flux:link :href="route('invoices.show', $settlement->invoice)" wire:navigate>{{ $settlement->invoice->displayNumber() }}</flux:link>
                                    <flux:badge size="sm" :color="$settlement->invoice->status->color()">{{ $settlement->invoice->status->label() }}</flux:badge>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($settlement->invoice)
                                    <flux:button size="sm" variant="ghost" icon="document-duplicate" target="_blank" :href="route('invoices.package', $settlement->invoice)">{{ __('Package') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</section>
