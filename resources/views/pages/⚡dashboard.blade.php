<?php

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\KsefStatus;
use App\Enums\Permission;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Note;
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
     * Dokumenty (kontrahentów, notatek), których ważność minęła albo mija w ciągu Attachment::WARN_DAYS dni.
     *
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function expiringDocuments(): Collection
    {
        $allowed = array_keys(array_filter(Attachment::PERMISSIONS, fn (string $gate) => Auth::user()->can($gate)));

        if ($allowed === []) {
            return collect();
        }

        return Attachment::query()->expiringSoon()->whereIn('attachable_type', $allowed)
            ->with('attachable')->orderBy('expires_at')->get();
    }

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
     * Liczba otwartych części tygodni z godzinami (pracownik — swoimi).
     */
    #[Computed]
    public function openWeeks(): int
    {
        $all = Auth::user()->hasPermission(Permission::ViewAllTimeEntries);

        return WorkWeek::query()
            ->whereNull('closed_at')
            ->whereHas('timeEntries', fn ($entries) => $entries->when(! $all, fn ($query) => $query->where('user_id', Auth::id())))
            ->where('starts_on', '<=', CarbonImmutable::today()->toDateString())
            ->count();
    }

    /**
     * Sprzedaż i zakupy netto w PLN, miesiąc po miesiącu (ostatnie 12 miesięcy, wg daty wystawienia).
     * Faktury w walucie przeliczone kursem z faktury; bez kursu — pominięte i policzone w „missing”.
     *
     * @return array{months: list<array{key: string, label: string, sales: float, purchases: float}>, missing: int, sales: float, purchases: float}
     */
    #[Computed]
    public function monthly(): array
    {
        $start = CarbonImmutable::today()->startOfMonth()->subMonths(11);
        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->addMonths($i);
            $months[$month->format('Y-m')] = ['key' => $month->format('Y-m'), 'label' => $month->translatedFormat('M y'), 'sales' => 0.0, 'purchases' => 0.0];
        }

        $missing = 0;

        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Issued)
            ->where('kind', '!=', InvoiceKind::Proforma)
            ->where('issue_date', '>=', $start->toDateString())
            ->get(['direction', 'currency', 'net', 'exchange_rate', 'issue_date']);

        foreach ($invoices as $invoice) {
            $key = $invoice->issue_date->format('Y-m');

            if (! isset($months[$key])) {
                continue;
            }

            $pln = $invoice->currency === 'PLN' ? (float) $invoice->net : ($invoice->exchange_rate !== null ? (float) $invoice->net * (float) $invoice->exchange_rate : null);

            if ($pln === null) {
                $missing++;

                continue;
            }

            $months[$key][$invoice->direction === InvoiceDirection::Sales ? 'sales' : 'purchases'] += $pln;
        }

        return [
            'months' => array_values($months),
            'missing' => $missing,
            'sales' => array_sum(array_column($months, 'sales')),
            'purchases' => array_sum(array_column($months, 'purchases')),
        ];
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

    @if ($this->expiringDocuments->isNotEmpty())
        @php($anyExpired = $this->expiringDocuments->contains(fn ($document) => $document->expiryState() === 'expired'))
        <flux:callout icon="exclamation-triangle" :color="$anyExpired ? 'red' : 'amber'" :heading="__('Documents expiring soon')">
            <flux:callout.text>
                <ul class="space-y-1">
                    @foreach ($this->expiringDocuments as $document)
                        @php($owner = $document->attachable)
                        @php($days = (int) $document->daysToExpiry())
                        <li wire:key="expiring-{{ $document->id }}">
                            @if ($owner instanceof Contractor)
                                <flux:link :href="route('contractors.show', $owner)" wire:navigate>{{ $owner->name }}</flux:link>:
                            @elseif ($owner instanceof Note)
                                <flux:link :href="route('notes.edit', $owner)" wire:navigate>{{ $owner->title }}</flux:link>:
                            @endif
                            <span class="font-medium">{{ $document->label() }}</span> —
                            @if ($days < 0)
                                {{ __('expired on :date', ['date' => $document->expires_at->format('d.m.Y')]) }}
                            @elseif ($days === 0)
                                {{ __('expires today') }}
                            @else
                                {{ trans_choice('expires :date (in :count day)|expires :date (in :count days)', $days, ['date' => $document->expires_at->format('d.m.Y'), 'count' => $days]) }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Kafelki: kolor ikony mówi, czego dotyczą (czas, tygodnie, należności, dokumenty) --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <flux:card class="flex items-start gap-4">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300"><flux:icon.clock /></div>
            <div>
                <flux:text>{{ __('My hours this week') }}</flux:text>
                <flux:heading size="xl">{{ $hours($this->myHours['week']) }} h</flux:heading>
                <flux:text size="sm">{{ __('This month: :hours h', ['hours' => $hours($this->myHours['month'])]) }}</flux:text>
            </div>
        </flux:card>

        <flux:card class="flex items-start gap-4">
            <div @class(['flex size-11 shrink-0 items-center justify-center rounded-xl', 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' => $this->openWeeks > 0, 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' => $this->openWeeks === 0])><flux:icon.calendar-days /></div>
            <div>
                <flux:text>{{ __('Weeks to close') }}</flux:text>
                <flux:heading size="xl">{{ $this->openWeeks }}</flux:heading>
                <flux:link :href="route('weeks.index')" wire:navigate class="text-sm">{{ __('Weeks') }}</flux:link>
            </div>
        </flux:card>

        @can('manage-invoices')
            <flux:card class="flex items-start gap-4">
                <div @class(['flex size-11 shrink-0 items-center justify-center rounded-xl', 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300' => $this->receivables['overdue'] > 0, 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300' => $this->receivables['overdue'] === 0])><flux:icon.banknotes /></div>
                <div>
                    <flux:text>{{ __('Unpaid invoices') }}</flux:text>
                    <flux:heading size="xl">{{ $this->receivables['count'] }}</flux:heading>
                    <flux:text size="sm" @class(['text-red-600 dark:text-red-400' => $this->receivables['overdue'] > 0])>
                        {{ __('Overdue: :count', ['count' => $this->receivables['overdue']]) }}
                    </flux:text>
                    @foreach ($this->receivables['sums'] as $currency => $sum)
                        <flux:text size="sm">{{ $money($sum, $currency) }}</flux:text>
                    @endforeach
                </div>
            </flux:card>

            <flux:card class="flex items-start gap-4">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300"><flux:icon.document-text /></div>
                <div>
                    <flux:text>{{ __('Drafts / waiting for KSeF') }}</flux:text>
                    <flux:heading size="xl">{{ $this->receivables['drafts'] }} / {{ $this->receivables['pending'] }}</flux:heading>
                    <flux:link :href="route('invoices.index', ['status' => 'draft'])" wire:navigate class="text-sm">{{ __('Sales invoices') }}</flux:link>
                </div>
            </flux:card>
        @endcan
    </div>

    @can('manage-invoices')
        @include('partials.dashboard-sales-chart', ['data' => $this->monthly])
    @endcan

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
