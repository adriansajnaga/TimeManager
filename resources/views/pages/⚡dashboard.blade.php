<?php

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\KsefStatus;
use App\Enums\Permission;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WorkWeek;
use App\Services\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Moje godziny: ten tydzień i ten miesiąc.
     *
     * @return array{week: float, month: float}
     */
    #[Computed]
    public function myHours(): array
    {
        $today = CarbonImmutable::today();
        $query = fn () => TimeEntry::query()->where('user_id', Auth::id());

        return [
            'week' => (float) $query()->whereBetween('work_date', [$today->startOfWeek()->toDateString(), $today->endOfWeek()->toDateString()])->sum('hours'),
            'month' => (float) $query()->whereBetween('work_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->sum('hours'),
        ];
    }

    /**
     * Otwarte części tygodni z godzinami (pracownik — swoimi), od najstarszej.
     *
     * @return Collection<int, WorkWeek>
     */
    #[Computed]
    public function openWeeks(): Collection
    {
        $all = Auth::user()->hasPermission(Permission::ViewAllTimeEntries);

        return WorkWeek::query()
            ->whereNull('closed_at')
            ->whereHas('timeEntries', fn ($entries) => $entries->when(! $all, fn ($query) => $query->where('user_id', Auth::id())))
            ->where('starts_on', '<=', CarbonImmutable::today()->toDateString())
            ->orderBy('starts_on')
            ->limit(8)
            ->get();
    }

    /**
     * Do rozliczenia: klienci z zamkniętymi, nierozliczonymi tygodniami i szacunkiem kwoty.
     *
     * @return list<array{client: Contractor, weeks: int, hours: string, total: string, ready: bool}>
     */
    #[Computed]
    public function toSettle(): array
    {
        if (! Auth::user()->hasPermission(Permission::ManageSettlements)) {
            return [];
        }

        $service = app(SettlementService::class);
        $rows = [];

        foreach (Contractor::query()->where('is_active', true)->whereHas('projects', fn ($projects) => $projects->where('billing_type', ProjectBillingType::Hourly))->orderBy('name')->get() as $client) {
            $weeks = $service->billableWeeks($client);

            if ($weeks->isEmpty()) {
                continue;
            }

            $preview = $service->preview($client, $weeks);
            $rows[] = [
                'client' => $client,
                'weeks' => $weeks->count(),
                'hours' => (string) $preview->hours,
                'total' => (string) $preview->total(),
                'ready' => $preview->problems() === [],
            ];
        }

        return $rows;
    }

    /**
     * Niezapłacone faktury sprzedaży: sumy w walutach, po terminie.
     *
     * @return array{count: int, overdue: int, sums: Collection<string, float>, drafts: int, pending: int}
     */
    #[Computed]
    public function receivables(): array
    {
        $unpaid = Invoice::query()
            ->sales()
            ->where('status', InvoiceStatus::Issued)
            ->where('kind', '!=', InvoiceKind::Proforma)
            ->whereNull('paid_on')
            ->get(['id', 'gross', 'currency', 'due_date']);

        return [
            'count' => $unpaid->count(),
            'overdue' => $unpaid->filter(fn (Invoice $invoice) => $invoice->due_date?->isPast() ?? false)->count(),
            'sums' => $unpaid->groupBy('currency')->map(fn (Collection $group) => (float) $group->sum('gross')),
            'drafts' => Invoice::query()->sales()->where('status', InvoiceStatus::Draft)->count(),
            'pending' => Invoice::query()->where('ksef_status', KsefStatus::Pending)->count(),
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function recentInvoices(): Collection
    {
        return Invoice::query()->sales()->latest('issue_date')->latest('id')->limit(6)->get();
    }

    /**
     * Aktywne projekty ryczałtowe: wartość umowy i zafakturowane netto.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function fixedProjects(): Collection
    {
        return Project::query()
            ->with('contractor')
            ->where('billing_type', ProjectBillingType::Fixed)
            ->where('status', ProjectStatus::Active)
            ->withSum(['invoices as invoiced_net' => fn ($invoices) => $invoices->where('status', InvoiceStatus::Issued)->where('kind', '!=', InvoiceKind::Proforma)], 'net')
            ->orderBy('number')
            ->limit(10)
            ->get();
    }
}; ?>

@php
    $hours = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',');
    $money = fn ($value, $currency) => number_format((float) $value, 2, ',', ' ').' '.$currency;
@endphp

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:text>{{ __('My hours this week') }}</flux:text>
            <flux:heading size="xl">{{ $hours($this->myHours['week']) }} h</flux:heading>
            <flux:text size="sm">{{ __('This month: :hours h', ['hours' => $hours($this->myHours['month'])]) }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text>{{ __('Weeks to close') }}</flux:text>
            <flux:heading size="xl">{{ $this->openWeeks->count() }}</flux:heading>
            <flux:link :href="route('weeks.index')" wire:navigate class="text-sm">{{ __('Weeks') }}</flux:link>
        </flux:card>

        @can('manage-invoices')
            <flux:card>
                <flux:text>{{ __('Unpaid invoices') }}</flux:text>
                <flux:heading size="xl">{{ $this->receivables['count'] }}</flux:heading>
                <flux:text size="sm" @class(['text-red-600 dark:text-red-400' => $this->receivables['overdue'] > 0])>
                    {{ __('Overdue: :count', ['count' => $this->receivables['overdue']]) }}
                </flux:text>
                @foreach ($this->receivables['sums'] as $currency => $sum)
                    <flux:text size="sm">{{ $money($sum, $currency) }}</flux:text>
                @endforeach
            </flux:card>

            <flux:card>
                <flux:text>{{ __('Drafts / waiting for KSeF') }}</flux:text>
                <flux:heading size="xl">{{ $this->receivables['drafts'] }} / {{ $this->receivables['pending'] }}</flux:heading>
                <flux:link :href="route('invoices.index', ['status' => 'draft'])" wire:navigate class="text-sm">{{ __('Sales invoices') }}</flux:link>
            </flux:card>
        @endcan
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($this->toSettle !== [])
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('Ready to settle') }}</flux:heading>
                @foreach ($this->toSettle as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <flux:link :href="route('settlements.index', ['client' => $row['client']->id])" wire:navigate>{{ $row['client']->name }}</flux:link>
                            <flux:text size="sm">{{ trans_choice(':count week part|:count week parts', $row['weeks'], ['count' => $row['weeks']]) }} · {{ $hours($row['hours']) }} h</flux:text>
                        </div>
                        <div class="text-end">
                            <flux:heading>{{ $money($row['total'], $row['client']->currency) }}</flux:heading>
                            @unless ($row['ready'])
                                <flux:badge size="sm" color="amber">{{ __('Data missing') }}</flux:badge>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </flux:card>
        @endif

        @if ($this->openWeeks->isNotEmpty())
            <flux:card class="space-y-2">
                <flux:heading size="lg">{{ __('Weeks to close') }}</flux:heading>
                @foreach ($this->openWeeks as $week)
                    <flux:link class="block" :href="route('weeks.show', $week)" wire:navigate>{{ $week->label() }}</flux:link>
                @endforeach
            </flux:card>
        @endif

        @can('manage-invoices')
            @if ($this->recentInvoices->isNotEmpty())
                <flux:card class="space-y-2">
                    <flux:heading size="lg">{{ __('Recent invoices') }}</flux:heading>
                    @foreach ($this->recentInvoices as $invoice)
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <flux:link :href="route('invoices.show', $invoice)" wire:navigate>{{ $invoice->displayNumber() }}</flux:link>
                                <flux:text size="sm" class="inline">{{ $invoice->counterparty_name }}</flux:text>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:text>{{ $money($invoice->gross, $invoice->currency) }}</flux:text>
                                <flux:badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge>
                            </div>
                        </div>
                    @endforeach
                </flux:card>
            @endif

            @if ($this->fixedProjects->isNotEmpty())
                <flux:card class="space-y-2">
                    <flux:heading size="lg">{{ __('Fixed-price projects') }}</flux:heading>
                    @foreach ($this->fixedProjects as $project)
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:link :href="route('projects.show', $project)" wire:navigate>{{ $project->fullName() }}</flux:link>
                            <flux:text size="sm">
                                {{ $money($project->invoiced_net ?? 0, $project->contract_currency ?: $project->contractor->currency) }}
                                / {{ $money($project->contract_value ?? 0, $project->contract_currency ?: $project->contractor->currency) }}
                            </flux:text>
                        </div>
                    @endforeach
                </flux:card>
            @endif
        @endcan
    </div>
</section>
