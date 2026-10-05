<?php

use App\Enums\Permission;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Contractor;
use App\Models\MaterialEntry;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Services\Ai\AiException;
use App\Services\Ai\TextAssistant;
use App\Services\Mileage\MileageCalculator;
use App\Services\Mileage\MileageTrip;
use App\Support\Hours;
use Brick\Math\BigDecimal;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public WorkWeek $workWeek;

    /** @var array<int, string> Ausgeführte Arbeiten wg id raportu */
    public array $performed = [];

    /** @var array<int, string> Restarbeiten wg id raportu */
    public array $remaining = [];

    /** @var array<int, list<array{name: string, quantity: string, unit: string}>> */
    public array $materials = [];

    /** @var array<int, array<string, string>> Propozycje AI wg raportu i pola (performed/remaining) */
    public array $suggestions = [];

    /** Koszt ostatniego zapytania do AI i szacowane saldo konta. */
    public ?string $aiCost = null;

    /** @var array<string, string> Kilometry wg dnia kilometrówki (klucz: osoba-klient-data) */
    public array $mileageKm = [];

    /** @var array<string, string> Trasa wg dnia kilometrówki */
    public array $mileageRoute = [];

    public function mount(WorkWeek $workWeek): void
    {
        $this->workWeek = $workWeek;

        foreach ($this->reports as $report) {
            $this->loadReport($report);
        }

        $this->loadMileage();
    }

    /**
     * Raporty projektów z godzinami w tej części tygodnia (dla pracownika — tylko jego projekty).
     *
     * @return Collection<int, WeeklyReport>
     */
    #[Computed]
    public function reports(): Collection
    {
        $projectIds = TimeEntry::query()
            ->where('work_week_id', $this->workWeek->id)
            ->when(! $this->seesAll(), fn ($query) => $query->whereHas('project.users', fn ($users) => $users->whereKey(Auth::id())))
            ->distinct()
            ->pluck('project_id');

        return Project::query()
            ->whereIn('id', $projectIds)
            ->with('contractor')
            ->orderBy('number')
            ->get()
            ->map(fn (Project $project) => WeeklyReport::query()
                ->firstOrCreate(['work_week_id' => $this->workWeek->id, 'project_id' => $project->id])
                ->setRelation('project', $project)
                ->setRelation('workWeek', $this->workWeek));
    }

    /**
     * Wpisy godzin projektu w tej części tygodnia.
     *
     * @return Collection<int, TimeEntry>
     */
    public function entriesFor(WeeklyReport $report): Collection
    {
        return TimeEntry::query()
            ->with('user')
            ->where('work_week_id', $this->workWeek->id)
            ->where('project_id', $report->project_id)
            ->orderBy('work_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Pary (osoba, klient) do Stundennachweis.
     *
     * @return Collection<int, object{user_id: int, user_name: string, contractor_id: int, contractor_name: string}>
     */
    #[Computed]
    public function timeRecords(): Collection
    {
        return DB::table('time_entries')
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->join('contractors', 'contractors.id', '=', 'projects.contractor_id')
            ->where('time_entries.work_week_id', $this->workWeek->id)
            ->when(! $this->seesAll(), fn ($query) => $query->where('time_entries.user_id', Auth::id()))
            ->select('users.id as user_id', 'users.name as user_name', 'contractors.id as contractor_id', 'contractors.name as contractor_name')
            ->distinct()
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Propozycja AI dla pola opisu: poprawa w tym samym języku albo tłumaczenie na język dokumentów klienta.
     */
    public function suggest(int $reportId, string $field, bool $translate): void
    {
        abort_unless(in_array($field, ['performed', 'remaining'], true), 404);

        $report = WeeklyReport::query()->with('project.contractor', 'workWeek')->findOrFail($reportId);
        $this->authorize('update', $report);

        $text = trim($this->{$field}[$reportId] ?? '');

        if ($text === '') {
            $this->addError("ai.$reportId.$field", __('Write a draft first.'));

            return;
        }

        $before = AiUsageLog::query()->max('id');

        try {
            $this->suggestions[$reportId][$field] = app(TextAssistant::class)->rewrite(
                $text,
                $field,
                $translate ? $report->project->contractor->document_language : null,
            );
        } catch (AiException $exception) {
            $this->addError("ai.$reportId.$field", $exception->getMessage());
        } finally {
            $this->aiCost = $this->costLine($before);
        }
    }

    /**
     * „Koszt: $0,0032 · zostało ok. $4,97” po zapytaniu do AI (gdy zapisano zużycie).
     */
    private function costLine(mixed $before): ?string
    {
        $log = AiUsageLog::query()->when($before !== null, fn ($query) => $query->where('id', '>', $before))->latest('id')->first();

        if ($log === null) {
            return null;
        }

        $line = __('AI cost: $:cost', ['cost' => number_format((float) $log->cost_usd, 4, ',', ' ')]);
        $balance = AiSetting::current()->estimatedBalance();

        return $balance === null
            ? $line
            : $line.' · '.__('about $:balance left on the account', ['balance' => number_format($balance->toFloat(), 2, ',', ' ')]);
    }

    public function acceptSuggestion(int $reportId, string $field): void
    {
        abort_unless(in_array($field, ['performed', 'remaining'], true), 404);

        if (isset($this->suggestions[$reportId][$field])) {
            $this->{$field}[$reportId] = $this->suggestions[$reportId][$field];
        }

        unset($this->suggestions[$reportId][$field]);
    }

    public function discardSuggestion(int $reportId, string $field): void
    {
        unset($this->suggestions[$reportId][$field]);
    }

    public function aiEnabled(): bool
    {
        return AiSetting::current()->isConfigured();
    }

    public function appendDescription(int $reportId, int $entryId): void
    {
        $entry = TimeEntry::query()->findOrFail($entryId);
        $current = trim($this->performed[$reportId] ?? '');

        $this->performed[$reportId] = trim($current.' '.$entry->description);
    }

    public function addMaterial(int $reportId): void
    {
        $this->materials[$reportId][] = ['name' => '', 'quantity' => '', 'unit' => MaterialEntry::UNITS[0]];
    }

    public function removeMaterial(int $reportId, int $index): void
    {
        unset($this->materials[$reportId][$index]);
        $this->materials[$reportId] = array_values($this->materials[$reportId]);
    }

    public function saveReport(int $reportId): void
    {
        $report = WeeklyReport::query()->with('project', 'workWeek')->findOrFail($reportId);
        $this->authorize('update', $report);

        $this->validate([
            "performed.$reportId" => ['nullable', 'string', 'max:5000'],
            "remaining.$reportId" => ['nullable', 'string', 'max:5000'],
            "materials.$reportId" => ['array'],
            "materials.$reportId.*.name" => ['required', 'string', 'max:255'],
            "materials.$reportId.*.quantity" => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            "materials.$reportId.*.unit" => ['required', Rule::in(MaterialEntry::UNITS)],
        ], attributes: [
            "materials.$reportId.*.name" => __('material'),
        ]);

        DB::transaction(function () use ($report, $reportId) {
            $report->update([
                'performed_work' => trim($this->performed[$reportId] ?? '') ?: null,
                'remaining_work' => trim($this->remaining[$reportId] ?? '') ?: null,
            ]);

            $report->materials()->delete();

            foreach ($this->materials[$reportId] ?? [] as $position => $material) {
                $report->materials()->create([
                    'position' => $position,
                    'name' => trim($material['name']),
                    'quantity' => $material['quantity'] === '' ? null : $material['quantity'],
                    'unit' => $material['unit'],
                ]);
            }
        });

        unset($this->reports);
        Flux::toast(variant: 'success', text: __('Report saved.'));
    }

    /**
     * Klienci z godzinami w tej części tygodnia (pracownik — z jego projektów) ze stanem zamknięcia.
     *
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function clients(): Collection
    {
        $hours = TimeEntry::query()
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->where('time_entries.work_week_id', $this->workWeek->id)
            ->when(! $this->seesAll(), fn ($query) => $query->whereIn('time_entries.project_id', $this->reports->pluck('project_id')))
            ->groupBy('projects.contractor_id')
            ->selectRaw('projects.contractor_id as client_id, SUM(time_entries.hours) as total')
            ->pluck('total', 'client_id');

        $closures = $this->workWeek->closures()->with('closedBy')->get()->keyBy('contractor_id');

        return Contractor::query()->whereIn('id', $hours->keys())->orderBy('name')->get()
            ->each(function (Contractor $client) use ($hours, $closures) {
                $client->setAttribute('week_hours', (string) $hours[$client->id]);
                $client->setRelation('weekClosure', $closures->get($client->id));
            });
    }

    public function close(int $contractorId): void
    {
        $this->authorize('close-weeks');

        // Każdy projekt klienta z godzinami w tej części (wszystkich osób) musi mieć opisane prace.
        $withHours = TimeEntry::query()->where('work_week_id', $this->workWeek->id)
            ->whereHas('project', fn ($projects) => $projects->where('contractor_id', $contractorId))
            ->distinct()->pluck('project_id');

        abort_if($withHours->isEmpty(), 404);

        $described = WeeklyReport::query()
            ->where('work_week_id', $this->workWeek->id)
            ->whereNotNull('performed_work')
            ->where('performed_work', '<>', '')
            ->pluck('project_id');
        $missing = Project::query()->whereIn('id', $withHours->diff($described))->orderBy('number')->pluck('number');

        if ($missing->isNotEmpty()) {
            $this->addError('close', __('Describe the work first for: :projects.', ['projects' => $missing->implode(', ')]));

            return;
        }

        $withoutKm = collect(app(MileageCalculator::class)->trips(collect([$this->workWeek])))
            ->filter(fn (MileageTrip $trip) => $trip->client->id === $contractorId && $trip->needsKm())
            ->map(fn (MileageTrip $trip) => $trip->date->format('d.m'));

        if ($withoutKm->isNotEmpty()) {
            $this->addError('close', __('Enter the mileage kilometres for: :days.', ['days' => $withoutKm->unique()->implode(', ')]));

            return;
        }

        $this->workWeek->closeFor($contractorId, Auth::user());
        $this->refreshState();
        Flux::toast(variant: 'success', text: __('Week closed for :client.', ['client' => Contractor::query()->whereKey($contractorId)->value('name')]));
    }

    public function reopen(int $contractorId): void
    {
        $this->authorize('close-weeks');

        if ($this->workWeek->settlements()->where('contractor_id', $contractorId)->exists()) {
            $this->addError('close', __('This week is already settled. Delete the draft invoice of the settlement first.'));

            return;
        }

        $this->workWeek->reopenFor($contractorId);
        $this->refreshState();
        Flux::toast(variant: 'success', text: __('Week reopened for :client.', ['client' => Contractor::query()->whereKey($contractorId)->value('name')]));
    }

    private function refreshState(): void
    {
        $this->workWeek->refresh();
        unset($this->clients, $this->reports, $this->mileageTrips);
    }

    /**
     * Dni z kilometrówką w tej części tygodnia (pracownik widzi swoje).
     *
     * @return list<MileageTrip>
     */
    #[Computed]
    public function mileageTrips(): array
    {
        return app(MileageCalculator::class)->trips(collect([$this->workWeek]), user: $this->seesAll() ? null : Auth::user());
    }

    public function saveMileage(string $key): void
    {
        $this->authorize('log-own-time');
        $trip = collect($this->mileageTrips)->first(fn (MileageTrip $trip) => $trip->key() === $key);
        abort_if($trip === null, 404);
        abort_if($this->workWeek->isClosedFor($trip->client), 403);

        $this->validate([
            "mileageKm.{$key}" => ['nullable', 'numeric', 'min:0', 'max:9999', 'decimal:0,1'],
            "mileageRoute.{$key}" => ['nullable', 'string', 'max:500'],
        ], attributes: ["mileageKm.{$key}" => __('km'), "mileageRoute.{$key}" => __('Route')]);

        $km = trim((string) ($this->mileageKm[$key] ?? ''));
        $route = trim((string) ($this->mileageRoute[$key] ?? ''));

        // Wartości równe wyliczonym nie są poprawką.
        $autoKm = count($trip->projects) === 1 && $trip->projects[0]->km_one_way !== null
            ? (string) BigDecimal::of($trip->projects[0]->km_one_way)->multipliedBy(2)
            : null;
        $autoRoute = MileageCalculator::route($trip->client, $trip->projects);

        app(MileageCalculator::class)->saveDay(
            $trip->user,
            $trip->client,
            $trip->date,
            $km === '' || ($autoKm !== null && BigDecimal::of($km)->isEqualTo($autoKm)) ? null : $km,
            $route === '' || $route === $autoRoute ? null : $route,
        );

        unset($this->mileageTrips);
        $this->loadMileage();
        Flux::toast(variant: 'success', text: __('Mileage saved.'));
    }

    private function loadMileage(): void
    {
        foreach ($this->mileageTrips as $trip) {
            $this->mileageKm[$trip->key()] = $trip->kmLabel();
            $this->mileageRoute[$trip->key()] = $trip->route;
        }
    }

    public function seesAll(): bool
    {
        return Auth::user()->hasPermission(Permission::ViewAllTimeEntries);
    }

    public function render()
    {
        return $this->view()->title($this->workWeek->label());
    }

    private function loadReport(WeeklyReport $report): void
    {
        $this->performed[$report->id] = (string) $report->performed_work;
        $this->remaining[$report->id] = (string) $report->remaining_work;
        $this->materials[$report->id] = $report->materials()->get()
            ->map(fn (MaterialEntry $material) => [
                'name' => $material->name,
                'quantity' => $material->quantityLabel(),
                'unit' => $material->unit,
            ])
            ->all();
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $workWeek->label() }}</flux:heading>
            <flux:subheading>
                <flux:link :href="route('weeks.index')" wire:navigate>{{ __('Weeks') }}</flux:link>
                · {{ $workWeek->starts_on->translatedFormat('F Y') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @php($closedCount = $this->clients->filter(fn ($client) => $client->weekClosure !== null)->count())
            @if ($this->clients->isNotEmpty() && $closedCount === $this->clients->count())
                <flux:badge color="green" icon="lock-closed">{{ __('Closed') }}</flux:badge>
            @elseif ($closedCount > 0)
                <flux:badge color="amber" icon="lock-open">{{ __(':closed of :total clients closed', ['closed' => $closedCount, 'total' => $this->clients->count()]) }}</flux:badge>
            @else
                <flux:badge color="zinc" icon="lock-open">{{ str(__('open'))->ucfirst() }}</flux:badge>
            @endif

            @if ($this->reports->isNotEmpty())
                <flux:button size="sm" icon="document-arrow-down" :href="route('documents.montageauftraege', $workWeek)" target="_blank">
                    {{ __('All reports (PDF)') }}
                </flux:button>
            @endif

        </div>
    </div>

    {{-- Zamykanie osobno dla każdego klienta --}}
    @if ($this->clients->isNotEmpty())
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Closing per client') }}</flux:heading>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->clients as $client)
                    <div wire:key="client-{{ $client->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <div class="font-medium">{{ $client->name }}</div>
                            <div class="text-sm text-zinc-500">
                                {{ Hours::format((string) $client->week_hours) }} h
                                @if ($client->weekClosure)
                                    · {{ __('closed :date', ['date' => $client->weekClosure->closed_at->format('d.m.Y H:i')]) }}@if ($client->weekClosure->closedBy) ({{ $client->weekClosure->closedBy->name }})@endif
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$client->weekClosure ? 'green' : 'zinc'" :icon="$client->weekClosure ? 'lock-closed' : 'lock-open'">
                                {{ $client->weekClosure ? __('Closed') : str(__('open'))->ucfirst() }}
                            </flux:badge>
                            @can('close-weeks')
                                @if ($client->weekClosure)
                                    <flux:button size="sm" icon="lock-open" wire:click="reopen({{ $client->id }})"
                                        wire:confirm="{{ __('Reopen this week for :client? Their entries will become editable again.', ['client' => $client->name]) }}">
                                        {{ __('Reopen') }}
                                    </flux:button>
                                @else
                                    <flux:button size="sm" variant="primary" icon="lock-closed" wire:click="close({{ $client->id }})" data-test="close-week-button-{{ $client->id }}">
                                        {{ __('Close week') }}
                                    </flux:button>
                                @endif
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
            <flux:error name="close" />
        </flux:card>
    @endif

    @if ($this->timeRecords->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-zinc-500">{{ __('Weekly time record') }}:</span>
            @foreach ($this->timeRecords as $record)
                <flux:button size="xs" icon="document-text" target="_blank"
                    :href="route('documents.stundennachweis', [$workWeek, $record->user_id, $record->contractor_id])">
                    {{ $record->user_name }} · {{ $record->contractor_name }}
                </flux:button>
            @endforeach
        </div>
    @endif

    @if ($this->mileageTrips !== [])
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Mileage') }}</flux:heading>
                <flux:text>{{ __('One trip per day: base → sites → base. With one project the distance is 2 × one way; with several projects enter the kilometres.') }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    @if ($this->seesAll())
                        <flux:table.column>{{ __('Person') }}</flux:table.column>
                    @endif
                    <flux:table.column>{{ __('Route') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('km') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->mileageTrips as $trip)
                        @php($tripClosed = $workWeek->isClosedFor($trip->client))
                        <flux:table.row :key="'trip-'.$trip->key()">
                            <flux:table.cell>{{ $trip->date->format('d.m.Y') }}</flux:table.cell>
                            @if ($this->seesAll())
                                <flux:table.cell>{{ $trip->user->name }}</flux:table.cell>
                            @endif
                            <flux:table.cell class="min-w-80">
                                @if ($tripClosed)
                                    {{ $trip->route }}
                                @else
                                    <flux:input size="sm" wire:model="mileageRoute.{{ $trip->key() }}" />
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="w-28">
                                @if ($tripClosed)
                                    {{ $trip->kmLabel() ?: '—' }}
                                @else
                                    <flux:input size="sm" wire:model="mileageKm.{{ $trip->key() }}" inputmode="decimal" :invalid="$trip->needsKm()" :placeholder="__('km')" />
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @unless ($tripClosed)
                                    <flux:button size="sm" variant="ghost" icon="check" wire:click="saveMileage('{{ $trip->key() }}')" :aria-label="__('Save')" />
                                @endunless
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:error name="mileage" />
        </flux:card>
    @endif

    @forelse ($this->reports as $report)
        @php($entries = $this->entriesFor($report))
        @php($editable = auth()->user()->can('update', $report))

        <flux:card class="space-y-5" wire:key="report-{{ $report->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ $report->project->fullName() }}</flux:heading>
                    <flux:text>{{ $report->project->contractor->name }} · {{ collect([$report->project->site_name, $report->project->site_city])->filter()->implode(', ') }}</flux:text>
                </div>

                <div class="flex items-center gap-2">
                    <flux:badge size="sm" :color="filled($performed[$report->id] ?? '') ? 'green' : 'amber'">
                        {{ Hours::format((string) $entries->sum('hours')) }} h
                    </flux:badge>
                    <flux:button size="sm" icon="document-arrow-down" target="_blank"
                        :href="route('documents.montageauftrag', [$workWeek, $report->project])">
                        {{ __('PDF') }}
                    </flux:button>
                </div>
            </div>

            {{-- Opisy dzienne z przyciskiem „Dodaj” do opisu tygodnia --}}
            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($entries as $entry)
                    <div class="flex items-start gap-3 px-3 py-2" wire:key="report-entry-{{ $entry->id }}">
                        <div class="w-40 shrink-0 tabular-nums">
                            {{ $entry->work_date->translatedFormat('D d.m') }} · {{ Hours::format($entry->hours) }} h
                            @if ($this->seesAll())
                                <div class="text-xs text-zinc-500">{{ $entry->user->name }}</div>
                            @endif
                        </div>
                        <div class="flex-1 text-zinc-600 dark:text-zinc-300">{{ $entry->description ?: '—' }}</div>
                        @if ($editable && filled($entry->description))
                            <flux:button size="xs" wire:click="appendDescription({{ $report->id }}, {{ $entry->id }})">{{ __('Add') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="space-y-2">
                <flux:textarea wire:model="performed.{{ $report->id }}" :label="__('Work performed')" rows="4" :disabled="! $editable" />

                @if ($editable && $this->aiEnabled())
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button size="xs" icon="sparkles" wire:click="suggest({{ $report->id }}, 'performed', false)" wire:loading.attr="disabled" wire:target="suggest">{{ __('AI: correct') }}</flux:button>
                        <flux:button size="xs" icon="language" wire:click="suggest({{ $report->id }}, 'performed', true)" wire:loading.attr="disabled" wire:target="suggest">
                            {{ __('AI: into :language', ['language' => $report->project->contractor->document_language->label()]) }}
                        </flux:button>
                        <flux:text wire:loading wire:target="suggest" class="text-xs">{{ __('Working…') }}</flux:text>
                    </div>
                    <flux:error name="ai.{{ $report->id }}.performed" />
                @endif

                @if (isset($suggestions[$report->id]['performed']))
                    <div class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-800 dark:bg-sky-950/40">
                        <flux:textarea wire:model="suggestions.{{ $report->id }}.performed" :label="__('AI suggestion')" rows="4" />
                        <div class="flex gap-2">
                            <flux:button size="xs" variant="primary" icon="check" wire:click="acceptSuggestion({{ $report->id }}, 'performed')">{{ __('Use') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="discardSuggestion({{ $report->id }}, 'performed')">{{ __('Discard') }}</flux:button>
                            @if ($aiCost)
                                <flux:text size="sm" class="ms-auto self-center">{{ $aiCost }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
            <div class="space-y-2">
                <flux:textarea wire:model="remaining.{{ $report->id }}" :label="__('Remaining work')" rows="2" :disabled="! $editable" />

                @if ($editable && $this->aiEnabled())
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:button size="xs" icon="sparkles" wire:click="suggest({{ $report->id }}, 'remaining', false)" wire:loading.attr="disabled" wire:target="suggest">{{ __('AI: correct') }}</flux:button>
                        <flux:button size="xs" icon="language" wire:click="suggest({{ $report->id }}, 'remaining', true)" wire:loading.attr="disabled" wire:target="suggest">
                            {{ __('AI: into :language', ['language' => $report->project->contractor->document_language->label()]) }}
                        </flux:button>
                        <flux:text wire:loading wire:target="suggest" class="text-xs">{{ __('Working…') }}</flux:text>
                    </div>
                    <flux:error name="ai.{{ $report->id }}.remaining" />
                @endif

                @if (isset($suggestions[$report->id]['remaining']))
                    <div class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-800 dark:bg-sky-950/40">
                        <flux:textarea wire:model="suggestions.{{ $report->id }}.remaining" :label="__('AI suggestion')" rows="2" />
                        <div class="flex gap-2">
                            <flux:button size="xs" variant="primary" icon="check" wire:click="acceptSuggestion({{ $report->id }}, 'remaining')">{{ __('Use') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="discardSuggestion({{ $report->id }}, 'remaining')">{{ __('Discard') }}</flux:button>
                            @if ($aiCost)
                                <flux:text size="sm" class="ms-auto self-center">{{ $aiCost }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <flux:field>
                <flux:label>{{ __('Material') }}</flux:label>

                <div class="space-y-2">
                    @foreach ($materials[$report->id] ?? [] as $index => $material)
                        <div class="grid grid-cols-[1fr_7rem_6rem_auto] gap-2" wire:key="material-{{ $report->id }}-{{ $index }}">
                            <flux:input wire:model="materials.{{ $report->id }}.{{ $index }}.name" :placeholder="__('Description')" :disabled="! $editable" />
                            <flux:input wire:model="materials.{{ $report->id }}.{{ $index }}.quantity" :placeholder="__('Quantity')" inputmode="decimal" :disabled="! $editable" />
                            <flux:select wire:model="materials.{{ $report->id }}.{{ $index }}.unit" :disabled="! $editable">
                                @foreach (MaterialEntry::UNITS as $unit)
                                    <flux:select.option :value="$unit">{{ $unit }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @if ($editable)
                                <flux:button variant="ghost" icon="x-mark" wire:click="removeMaterial({{ $report->id }}, {{ $index }})" :aria-label="__('Remove')" />
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($editable)
                    <div class="mt-2">
                        <flux:button size="sm" icon="plus" wire:click="addMaterial({{ $report->id }})">{{ __('Add material') }}</flux:button>
                    </div>
                @endif
            </flux:field>

            @if ($editable)
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="saveReport({{ $report->id }})" data-test="save-report-{{ $report->id }}">{{ __('Save') }}</flux:button>
                </div>
            @endif
        </flux:card>
    @empty
        <flux:callout icon="information-circle">
            <flux:callout.text>{{ __('No working time in this part of the week.') }}</flux:callout.text>
        </flux:callout>
    @endforelse
</section>
