<?php

use App\Enums\ProtectionType;
use App\Enums\RcdType;
use App\Models\Attachment;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementMarker;
use App\Models\MeasurementPoint;
use App\Models\MeasurementProtocol;
use App\Models\MeasurementRcd;
use App\Services\Measurements\Criteria;
use App\Support\MeasurementInput;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public MeasurementProtocol $protocol;

    public MeasurementBoard $board;

    public string $boardName = '';

    /** Rozwinięty obwód (pozostałe pokazują jedną linię podsumowania). */
    public ?int $open = null;

    /** Rozwinięty RCD (pozostałe — jedna linia z wynikami). */
    public ?int $openRcd = null;

    /** Kolumna pętli N-PE (włączana, gdy mierzysz też N-PE). */
    public bool $withNpe = false;

    /** @var array<int, array<string, mixed>> */
    public array $rcds = [];

    /** @var array<int, array<string, mixed>> */
    public array $circuits = [];

    /** @var array<int, array<string, string>> */
    public array $points = [];

    public function mount(MeasurementProtocol $protocol, MeasurementBoard $board): void
    {
        abort_unless($board->protocol_id === $protocol->id, 404);

        $this->protocol = $protocol;
        $this->board = $board;
        $this->boardName = $board->name;
        $this->loadState();
        $this->withNpe = MeasurementPoint::query()->whereIn('circuit_id', array_keys($this->circuits))->whereNotNull('impedance_npe')->exists();
        $this->open = $board->isSupply() ? (array_key_first($this->circuits) ?: null) : null;
    }

    private function loadState(): void
    {
        $this->rcds = $this->board->rcds()->get()->mapWithKeys(fn (MeasurementRcd $rcd) => [$rcd->id => [
            'designation' => $rcd->designation,
            'model' => (string) $rcd->model,
            'type' => $rcd->type->value,
            'selective' => $rcd->selective,
            'rated_current' => MeasurementInput::show($rcd->rated_current),
            'rated_residual' => (string) $rcd->rated_residual,
            'trip_time' => MeasurementInput::show($rcd->trip_time),
            'trip_current' => MeasurementInput::show($rcd->trip_current),
            'contact_voltage' => MeasurementInput::show($rcd->contact_voltage),
            'test_button' => $rcd->test_button,
        ]])->all();

        $circuits = $this->board->circuits()->with('points')->get();

        $this->circuits = $circuits->mapWithKeys(fn (MeasurementCircuit $circuit) => [$circuit->id => [
            'number' => (string) $circuit->number,
            'name' => $circuit->name,
            'phases' => (string) $circuit->phases,
            'protection_type' => $circuit->protection_type?->value ?? '',
            'protection_current' => MeasurementInput::show($circuit->protection_current),
            'trip_current_override' => $this->tripCurrentField($circuit),
            'cable' => (string) $circuit->cable,
            'rcd_id' => $circuit->rcd_id !== null ? (string) $circuit->rcd_id : '',
            'insulation_voltage' => (string) $circuit->insulation_voltage,
            'insulation' => array_map('strval', $circuit->insulation ?? []),
        ]])->all();

        $this->points = $circuits->flatMap(fn (MeasurementCircuit $circuit) => $circuit->points)
            ->mapWithKeys(fn (MeasurementPoint $point) => [$point->id => [
                'symbol' => (string) $point->symbol,
                'location' => (string) $point->location,
                'impedance' => MeasurementInput::show($point->impedance),
                'impedance_npe' => MeasurementInput::show($point->impedance_npe),
            ]])->all();
    }

    /**
     * Obwody z punktami do wyliczeń (Ia, Za, Ik, ocena) — po każdym zapisie od nowa.
     *
     * @return Collection<int, MeasurementCircuit>
     */
    #[Computed]
    public function circuitModels(): Collection
    {
        return $this->board->circuits()->with(['points.marker', 'rcd'])->get();
    }

    /**
     * @return Collection<int, MeasurementRcd>
     */
    #[Computed]
    public function rcdModels(): Collection
    {
        return $this->board->rcds()->get();
    }

    public function updatedBoardName(): void
    {
        $this->authorize('manage-measurements');
        $this->board->update(['name' => mb_substr(trim($this->boardName), 0, 100) ?: $this->board->name]);
    }

    // --- RCD ---------------------------------------------------------------------------------

    public function addRcd(): void
    {
        $this->authorize('manage-measurements');
        $last = $this->board->rcds()->reorder()->latest('position')->first();

        $this->openRcd = $this->board->rcds()->create([
            'position' => ($last->position ?? 0) + 1,
            'designation' => 'Fi'.(($last->position ?? 0) + 1),
            'model' => $last?->model,
            'type' => $last->type ?? RcdType::A,
            'rated_current' => $last?->rated_current,
            'rated_residual' => $last->rated_residual ?? 30,
        ])->id;

        $this->refreshState();
    }

    public function updatedRcds(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        [$id, $field] = explode('.', $key, 2);

        $data = match ($field) {
            'designation' => ['designation' => mb_substr(trim((string) $value), 0, 50) ?: 'Fi'],
            'model' => ['model' => trim((string) $value) ?: null],
            'type' => RcdType::tryFrom((string) $value) ? ['type' => (string) $value] : [],
            'selective' => ['selective' => (bool) $value],
            'test_button' => ['test_button' => (bool) $value],
            'rated_current' => ['rated_current' => MeasurementInput::decimal($value)],
            'rated_residual' => ['rated_residual' => (int) (MeasurementInput::decimal($value) ?? 30) ?: 30],
            'trip_time' => ['trip_time' => MeasurementInput::decimal($value)],
            'trip_current' => ['trip_current' => MeasurementInput::decimal($value)],
            'contact_voltage' => ['contact_voltage' => MeasurementInput::decimal($value)],
            default => [],
        };

        $this->board->rcds()->whereKey((int) $id)->update($data);
        unset($this->rcdModels, $this->circuitModels);
    }

    public function toggleRcd(int $id): void
    {
        $this->openRcd = $this->openRcd === $id ? null : $id;
    }

    public function deleteRcd(int $id): void
    {
        $this->authorize('manage-measurements');
        $this->board->rcds()->whereKey($id)->delete();
        $this->refreshState();
    }

    // --- Obwody ------------------------------------------------------------------------------

    /** Nowy obwód z zabezpieczeniem i RCD poprzedniego; numer 2F4 → 2F5. */
    public function addCircuit(): void
    {
        $this->authorize('manage-measurements');
        $last = $this->board->circuits()->reorder()->latest('position')->first();

        $number = $last?->number;
        if ($number !== null && preg_match('/^(.*?)(\d+)$/', $number, $match) === 1) {
            $number = $match[1].((int) $match[2] + 1);
        }

        $circuit = $this->board->circuits()->create([
            'position' => ($last->position ?? 0) + 1,
            'number' => $number,
            'name' => __('Circuit'),
            'phases' => $last->phases ?? 1,
            'protection_type' => $last->protection_type ?? ProtectionType::B,
            'protection_current' => $last->protection_current ?? 16,
            'cable' => $last?->cable,
            'rcd_id' => $last?->rcd_id,
            'insulation_voltage' => $last->insulation_voltage ?? 500,
        ]);

        $this->open = $circuit->id;
        $this->refreshState();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function updatedCircuits(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        $parts = explode('.', $key, 3);
        $circuit = $this->board->circuits()->whereKey((int) $parts[0])->first();

        if ($circuit === null) {
            return;
        }

        if ($parts[1] === 'insulation' && isset($parts[2]) && in_array($parts[2], $circuit->pairs(), true)) {
            $readings = $circuit->insulation ?? [];
            $readings[$parts[2]] = MeasurementInput::reading($value);
            $circuit->update(['insulation' => array_filter($readings, fn ($reading) => $reading !== null)]);
        } else {
            $circuit->update(match ($parts[1]) {
                'number' => ['number' => trim((string) $value) ?: null],
                'name' => ['name' => mb_substr(trim((string) $value), 0, 255) ?: __('Circuit')],
                'phases' => ['phases' => (int) $value === 3 ? 3 : 1],
                'protection_type' => ['protection_type' => ProtectionType::tryFrom((string) $value)],
                'protection_current' => ['protection_current' => MeasurementInput::decimal($value)],
                // Wartość równa wyliczonej (albo pusta) = automatycznie; inna = wpisana ręcznie.
                'trip_current_override' => ['trip_current_override' => $this->manualTripCurrent($circuit, MeasurementInput::decimal($value))],
                'cable' => ['cable' => trim((string) $value) ?: null],
                'rcd_id' => ['rcd_id' => $value !== '' && $this->board->rcds()->whereKey((int) $value)->exists() ? (int) $value : null],
                'insulation_voltage' => ['insulation_voltage' => in_array((int) $value, [250, 500, 1000], true) ? (int) $value : 500],
                default => [],
            });
        }

        // Zmiana zabezpieczenia przelicza Ia w polu (gdy nie było wpisane ręcznie).
        if (in_array($parts[1], ['protection_type', 'protection_current', 'trip_current_override'], true)) {
            $this->circuits[$circuit->id]['trip_current_override'] = $this->tripCurrentField($circuit->refresh());
        }

        unset($this->circuitModels);
    }

    private function tripCurrentField(MeasurementCircuit $circuit): string
    {
        $ia = $circuit->tripCurrent($this->protocol);

        return $ia === null ? '' : MeasurementInput::show(round($ia, 1));
    }

    private function manualTripCurrent(MeasurementCircuit $circuit, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $auto = Criteria::tripCurrent(
            $circuit->protection_type,
            $circuit->protection_current === null ? null : (float) $circuit->protection_current,
            $this->protocol->disconnectionTime(),
        );

        return $auto !== null && abs($auto - (float) $value) < 0.05 ? null : $value;
    }

    /** Wszystkie pary izolacji obwodu jednym kliknięciem (np. „>30”). */
    public function fillInsulation(int $id, string $reading): void
    {
        $this->authorize('manage-measurements');
        $circuit = $this->board->circuits()->whereKey($id)->first();
        $circuit?->update(['insulation' => array_fill_keys($circuit->pairs(), MeasurementInput::reading($reading))]);
        $this->refreshState();
    }

    public function deleteCircuit(int $id): void
    {
        $this->authorize('manage-measurements');
        $this->board->circuits()->whereKey($id)->delete();
        $this->pruneMarkers();
        $this->open = null;
        $this->refreshState();
    }

    // --- Punkty ------------------------------------------------------------------------------

    /**
     * Dodaje punkty: socket (G n), light (O n), phases (L1–L3) albo point (pusty).
     */
    public function addPoints(int $circuitId, string $kind): void
    {
        $this->authorize('manage-measurements');
        $circuit = $this->board->circuits()->whereKey($circuitId)->with('points')->first();

        if ($circuit === null) {
            return;
        }

        $position = (int) $circuit->points->max('position');
        $next = fn (string $prefix) => $prefix.($circuit->points->filter(fn (MeasurementPoint $point) => preg_match('/^'.$prefix.'\d+$/', (string) $point->symbol) === 1)->count() + 1);

        $new = match ($kind) {
            'socket' => [[$next('G'), $circuit->name.' - '.__('socket')]],
            'light' => [[$next('O'), $circuit->name.' - '.__('lighting')]],
            'phases' => [['L1', $circuit->name], ['L2', $circuit->name], ['L3', $circuit->name]],
            default => [['', $circuit->name]],
        };

        foreach ($new as [$symbol, $location]) {
            $circuit->points()->create(['position' => ++$position, 'symbol' => $symbol ?: null, 'location' => $location, 'loop' => 'L-PE']);
        }

        $this->open = $circuit->id;
        $this->refreshState();
    }

    public function updatedPoints(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        [$id, $field] = explode('.', $key, 2);

        $point = MeasurementPoint::query()->whereKey((int) $id)->whereIn('circuit_id', $this->board->circuits()->select('id'))->first();

        $point?->update(match ($field) {
            'symbol' => ['symbol' => mb_substr(trim((string) $value), 0, 30) ?: null],
            'location' => ['location' => mb_substr(trim((string) $value), 0, 255) ?: null],
            'impedance' => ['impedance' => MeasurementInput::decimal($value)],
            'impedance_npe' => ['impedance_npe' => MeasurementInput::decimal($value)],
            default => [],
        });

        unset($this->circuitModels);
    }

    public function deletePoint(int $id): void
    {
        $this->authorize('manage-measurements');
        MeasurementPoint::query()->whereKey($id)->whereIn('circuit_id', $this->board->circuits()->select('id'))->delete();
        $this->pruneMarkers();
        $this->refreshState();
    }

    // --- Rzut: znaczniki punktów ----------------------------------------------------------------

    /** Punkt, dla którego zaznaczamy miejsce na rzucie. */
    public ?int $planPoint = null;

    /** Wyświetlany rzut (obraz załączony do protokołu). */
    public ?int $planId = null;

    /**
     * Obrazy załączone do protokołu — rzuty do zaznaczania punktów.
     *
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function plans(): Collection
    {
        return $this->protocol->attachments()->get()->filter(fn (Attachment $attachment) => $attachment->kind() === 'image')->values();
    }

    /**
     * Znaczniki na wyświetlanym rzucie z symbolami ich punktów.
     *
     * @return Collection<int, MeasurementMarker>
     */
    #[Computed]
    public function planMarkers(): Collection
    {
        return $this->planId === null ? collect() : $this->protocol->markers()->where('attachment_id', $this->planId)->with(['points', 'board'])->get();
    }

    /** Tryb rzutu: stawianie rozdzielnicy (prostokąt z nazwą) zamiast punktu. */
    public bool $planBoard = false;

    public function openBoardPlan(): void
    {
        if ($this->plans->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('Add a floor plan image to the protocol first (Drawings and attachments).'));

            return;
        }

        $existing = $this->protocol->markers()->where('board_id', $this->board->id)->first();
        $this->planPoint = null;
        $this->planBoard = true;
        $this->planId = $existing?->attachment_id ?? ($this->planId !== null && $this->plans->contains('id', $this->planId) ? $this->planId : $this->plans->first()?->id);
        unset($this->planMarkers);

        Flux::modal('plan')->show();
    }

    /** Stawia (albo przesuwa) rozdzielnicę na wyświetlanym rzucie. */
    public function placeBoardMarker(float $x, float $y): void
    {
        $this->authorize('manage-measurements');

        if ($this->planId === null || ! $this->plans->contains('id', $this->planId)) {
            return;
        }

        $this->protocol->markers()->updateOrCreate(
            ['board_id' => $this->board->id],
            ['attachment_id' => $this->planId, 'number' => 0, 'x' => max(0, min(100, $x)), 'y' => max(0, min(100, $y))],
        );

        $this->planBoard = false;
        unset($this->planMarkers);
        Flux::modal('plan')->close();
    }

    public function removeBoardMarker(): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->markers()->where('board_id', $this->board->id)->delete();

        $this->planBoard = false;
        unset($this->planMarkers);
        Flux::modal('plan')->close();
    }
    public function openPlan(int $pointId): void
    {
        $point = $this->boardPoint($pointId);

        if ($point === null) {
            return;
        }

        if ($this->plans->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('Add a floor plan image to the protocol first (Drawings and attachments).'));

            return;
        }

        $this->planPoint = $point->id;
        $this->planBoard = false;
        $this->planId = $point->marker?->attachment_id ?? ($this->planId !== null && $this->plans->contains('id', $this->planId) ? $this->planId : $this->plans->first()?->id);
        unset($this->planMarkers);

        Flux::modal('plan')->show();
    }

    public function showPlan(int $attachmentId): void
    {
        if ($this->plans->contains('id', $attachmentId)) {
            $this->planId = $attachmentId;
            unset($this->planMarkers);
        }
    }

    /** Nowy znacznik w stukniętym miejscu (x, y w % obrazu) dla wybranego punktu. */
    public function placeMarker(float $x, float $y): void
    {
        $this->authorize('manage-measurements');
        $point = $this->planPoint !== null ? $this->boardPoint($this->planPoint) : null;

        if ($point === null || $this->planId === null || ! $this->plans->contains('id', $this->planId)) {
            return;
        }

        $previous = $point->marker;
        $marker = $this->protocol->markers()->create([
            'attachment_id' => $this->planId,
            'number' => MeasurementMarker::nextNumber($this->protocol),
            'x' => max(0, min(100, $x)),
            'y' => max(0, min(100, $y)),
        ]);

        $point->update(['marker_id' => $marker->id]);
        $this->dropIfEmpty($previous);
        $this->closePlan();
    }

    /** Dołącza punkt do istniejącego znacznika (grupa gniazd obok siebie). */
    public function assignMarker(int $markerId): void
    {
        $this->authorize('manage-measurements');
        $point = $this->planPoint !== null ? $this->boardPoint($this->planPoint) : null;
        $marker = $this->protocol->markers()->whereKey($markerId)->whereNull('board_id')->first();

        if ($point === null || $marker === null) {
            return;
        }

        $previous = $point->marker;
        $point->update(['marker_id' => $marker->id]);

        if ($previous?->id !== $marker->id) {
            $this->dropIfEmpty($previous);
        }

        $this->closePlan();
    }

    public function moveMarker(int $markerId, float $x, float $y): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->markers()->whereKey($markerId)->update(['x' => max(0, min(100, $x)), 'y' => max(0, min(100, $y))]);
        unset($this->planMarkers);
    }

    /** Odpina punkt od znacznika (znacznik bez punktów znika). */
    public function unassignPoint(): void
    {
        $this->authorize('manage-measurements');
        $point = $this->planPoint !== null ? $this->boardPoint($this->planPoint) : null;

        if ($point === null) {
            return;
        }

        $previous = $point->marker;
        $point->update(['marker_id' => null]);
        $this->dropIfEmpty($previous);
        $this->closePlan();
    }

    private function closePlan(): void
    {
        $this->planPoint = null;
        unset($this->planMarkers, $this->circuitModels);
        Flux::modal('plan')->close();
    }

    /** Znaczniki bez punktów (po usunięciu punktu lub obwodu) znikają z rzutu. */
    private function pruneMarkers(): void
    {
        $this->protocol->markers()->whereNull('board_id')->whereDoesntHave('points')->delete();
    }

    private function dropIfEmpty(?MeasurementMarker $marker): void
    {
        if ($marker !== null && ! $marker->isBoard() && ! $marker->points()->exists()) {
            $marker->delete();
        }
    }

    private function boardPoint(int $id): ?MeasurementPoint
    {
        return MeasurementPoint::query()->whereKey($id)->whereIn('circuit_id', $this->board->circuits()->select('id'))->with('marker')->first();
    }
    public function deleteBoard(): void
    {
        $this->authorize('manage-measurements');
        $this->board->delete();

        Flux::toast(variant: 'success', text: __('Board deleted.'));
        $this->redirectRoute('measurements.show', $this->protocol, navigate: true);
    }

    private function refreshState(): void
    {
        unset($this->circuitModels, $this->rcdModels);
        $this->loadState();
    }

    public function render()
    {
        return $this->view()->title($this->board->name.' · '.$this->protocol->number);
    }
}; ?>

@php
    $fmt = fn (?float $value, int $decimals = 2) => MeasurementInput::format($value, $decimals);
@endphp

<section class="w-full max-w-5xl space-y-5"
    x-data="{
        next(event) {
            const inputs = [...$root.querySelectorAll('input[data-measure]')];
            const target = inputs[inputs.indexOf(event.target) + 1];
            if (target) { target.focus(); target.select(); } else { event.target.blur(); }
        },
    }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('measurements.show', $protocol)" wire:navigate :aria-label="__('Back')" />
            <flux:input wire:model.blur="boardName" class="max-w-40 font-semibold" />
            <flux:text class="truncate">{{ $protocol->number }} · {{ $protocol->place }}</flux:text>
        </div>
        <div class="flex items-center gap-3">
            @unless ($board->isSupply())
                <flux:switch wire:model.live="withNpe" :label="__('N-PE loop')" />
            @endunless
            @unless ($board->isSupply())
                <flux:button size="sm" icon="view-columns" :href="route('measurements.layout', [$protocol, $board])" wire:navigate>{{ __('Elevation') }}</flux:button>
            @endunless
            <flux:button size="sm" icon="map-pin" wire:click="openBoardPlan">{{ __('On the plan') }}</flux:button>
            <flux:dropdown>
                <flux:button icon="ellipsis-vertical" size="sm" :aria-label="__('More')" />
                <flux:menu>
                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteBoard" wire:confirm="{{ __('Delete this board with all its results?') }}">{{ __('Delete board') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    {{-- RCD --}}
    @unless ($board->isSupply())
        <flux:card class="space-y-3 p-4">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('Residual current devices (RCD)') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="addRcd">{{ __('RCD') }}</flux:button>
            </div>

            @foreach ($this->rcdModels as $rcd)
                @php($failures = $rcd->failures($protocol->touch_voltage))
                @php($rcdOpen = $openRcd === $rcd->id)
                <div wire:key="rcd-{{ $rcd->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    {{-- Jedna linia: symbol, typ, wyniki, ocena --}}
                    <button type="button" wire:click="toggleRcd({{ $rcd->id }})" class="flex w-full items-center gap-3 px-3 py-2 text-start text-sm">
                        <span class="w-12 shrink-0 font-semibold">{{ $rcd->designation }}</span>
                        <span class="hidden min-w-0 flex-1 truncate text-zinc-500 sm:inline">{{ $rcd->type->value }}{{ $rcd->selective ? ' S' : '' }} {{ $rcd->rated_residual }} mA</span>
                        <span class="ms-auto whitespace-nowrap tabular-nums text-zinc-500 sm:ms-0">{{ $rcds[$rcd->id]['trip_time'] ?: '—' }} ms · {{ $rcds[$rcd->id]['trip_current'] ?: '—' }} mA</span>
                        <x-measure-verdict :passes="$failures === null ? null : $failures === []" />
                        <flux:icon :name="$rcdOpen ? 'chevron-up' : 'chevron-down'" class="size-4 text-zinc-400" />
                    </button>

                    @if ($rcdOpen)
                        <div class="space-y-2 border-t border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="grid grid-cols-3 gap-2">
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.trip_time" :label="__('t [ms]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                    :class="in_array('time', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.trip_current" :label="__('Ia [mA]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                    :class="in_array('current', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.contact_voltage" :label="__('Ud [V]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                    :class="in_array('contact_voltage', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                            </div>
                            <div class="flex flex-wrap gap-4">
                                <flux:checkbox wire:model.live="rcds.{{ $rcd->id }}.test_button" :label="__('TEST OK')" />
                                <flux:checkbox wire:model.live="rcds.{{ $rcd->id }}.selective" :label="__('S (selective)')" />
                            </div>
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-[5rem_1fr_5rem_5rem_5rem]">
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.designation" size="sm" :label="__('Symbol')" />
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.model" size="sm" :label="__('Device')" placeholder="PXF 40/4/003-A" class="col-span-2 sm:col-span-1" />
                                <flux:select wire:model.live="rcds.{{ $rcd->id }}.type" size="sm" :label="__('Type')">
                                    @foreach (RcdType::cases() as $type)
                                        <flux:select.option :value="$type->value">{{ $type->value }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.rated_current" size="sm" :label="__('In [A]')" inputmode="decimal" />
                                <flux:input wire:model.blur="rcds.{{ $rcd->id }}.rated_residual" size="sm" :label="__('IΔn [mA]')" inputmode="numeric" />
                            </div>
                            <div class="flex justify-end">
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRcd({{ $rcd->id }})" wire:confirm="{{ __('Delete this RCD?') }}">{{ __('Delete') }}</flux:button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </flux:card>
    @endunless

    {{-- Obwody --}}
    <div class="space-y-3">
        @foreach ($this->circuitModels as $circuit)
            @php($ia = $circuit->tripCurrent($protocol))
            @php($za = Criteria::allowedImpedance($protocol->phase_voltage, $ia))
            @php($results = $circuit->points->map(fn ($point) => $point->passes($za))->filter(fn ($result) => $result !== null))
            @php($npeResults = $withNpe ? $circuit->points->map(fn ($point) => $point->passes($za, npe: true))->filter(fn ($result) => $result !== null) : collect())
            @php($insulationOk = $circuit->insulationPasses())
            @php($worst = $results->merge($npeResults)->push($insulationOk)->filter(fn ($result) => $result !== null))
            @php($isOpen = $open === $circuit->id)

            <flux:card wire:key="circuit-{{ $circuit->id }}" class="p-0">
                {{-- Linia podsumowania --}}
                <button type="button" wire:click="toggle({{ $circuit->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start">
                    <span class="w-14 shrink-0 font-semibold">{{ $circuit->number ?: '—' }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $circuit->name }}</span>
                    <span class="hidden text-sm text-zinc-500 sm:inline">{{ $circuit->protectionLabel() }}{{ $circuit->rcd ? ' · '.$circuit->rcd->designation : '' }}</span>
                    <span class="text-sm text-zinc-500">{{ $results->count() }}/{{ $circuit->points->count() }}</span>
                    <x-measure-verdict :passes="$worst->isEmpty() ? null : ! $worst->contains(false)" />
                    <flux:icon :name="$isOpen ? 'chevron-up' : 'chevron-down'" class="size-4 text-zinc-400" />
                </button>

                @if ($isOpen)
                    <div class="space-y-4 border-t border-zinc-200 p-4 dark:border-zinc-700">
                        @unless ($board->isSupply())
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-[5rem_1fr_6rem_4.5rem]">
                                <flux:input wire:model.blur="circuits.{{ $circuit->id }}.number" size="sm" :label="__('No.')" placeholder="2F4" />
                                <flux:input wire:model.blur="circuits.{{ $circuit->id }}.name" size="sm" :label="__('Circuit / room')" />
                                <flux:input wire:model.blur="circuits.{{ $circuit->id }}.cable" size="sm" :label="__('Cable')" placeholder="3x2,5" />
                                <flux:select wire:model.live="circuits.{{ $circuit->id }}.phases" size="sm" :label="__('Phases')">
                                    <flux:select.option value="1">1F</flux:select.option>
                                    <flux:select.option value="3">3F</flux:select.option>
                                </flux:select>
                            </div>
                        @endunless

                        {{-- Zabezpieczenie: typ jednym kliknięciem, In, Ia ręcznie, RCD --}}
                        <div class="space-y-2">
                            <div class="flex gap-1">
                                @foreach (ProtectionType::cases() as $type)
                                    <flux:button size="sm" class="flex-1" :variant="$circuit->protection_type === $type ? 'primary' : 'outline'"
                                        wire:click="$set('circuits.{{ $circuit->id }}.protection_type', '{{ $type->value }}')">{{ $type->label() }}</flux:button>
                                @endforeach
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <flux:input wire:model.blur="circuits.{{ $circuit->id }}.protection_current" size="sm" :label="__('In [A]')" inputmode="decimal" list="in-values" />
                                <flux:input wire:model.blur="circuits.{{ $circuit->id }}.trip_current_override" size="sm" :label="__('Ia [A]')" inputmode="decimal"
                                    :description="$circuit->trip_current_override !== null ? __('entered manually — clear to calculate') : null" />
                                @unless ($board->isSupply())
                                    <flux:select wire:model.live="circuits.{{ $circuit->id }}.rcd_id" size="sm" :label="__('RCD')">
                                        <flux:select.option value="">—</flux:select.option>
                                        @foreach ($this->rcdModels as $rcd)
                                            <flux:select.option :value="(string) $rcd->id">{{ $rcd->designation }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endunless
                            </div>
                            <div class="text-sm text-zinc-500">
                                Ia {{ $fmt($ia, 0) }} A · Za {{ $fmt($za) }} Ω
                                @if ($ia === null && $circuit->protection_type === ProtectionType::GG)
                                    <span class="text-amber-600">· {{ __('enter Ia from the fuse catalogue') }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Punkty: symbol, Zs (duże pole), Ik; nazwa punktu w drugiej linii --}}
                        @php($pointGrid = $withNpe && ! $board->isSupply() ? 'grid-cols-[2.75rem_minmax(0,1fr)_2.25rem_minmax(0,1fr)_2.25rem]' : 'grid-cols-[3.5rem_minmax(0,1fr)_3rem]')
                        <div class="space-y-1">
                            <div class="grid {{ $pointGrid }} items-end gap-2 text-xs text-zinc-500">
                                <span>{{ __('Symbol') }}</span>
                                <span>Zs {{ $board->isSupply() ? '' : 'L-PE' }} [Ω]</span>
                                <span class="text-end">Ik</span>
                                @if ($withNpe && ! $board->isSupply())
                                    <span>Zs N-PE [Ω]</span>
                                    <span class="text-end">Ik</span>
                                @endif
                            </div>

                            @foreach ($circuit->points as $point)
                                @php($ok = $point->passes($za))
                                @php($okNpe = $point->passes($za, npe: true))
                                <div wire:key="point-{{ $point->id }}" class="border-t border-zinc-100 pt-1 dark:border-zinc-800">
                                    <div class="grid {{ $pointGrid }} items-center gap-2">
                                        @if ($board->isSupply())
                                            <span class="font-medium">{{ $point->symbol }}</span>
                                        @else
                                            <flux:input wire:model.blur="points.{{ $point->id }}.symbol" size="sm" class:input="px-1.5 text-center" />
                                        @endif
                                        <flux:input wire:model.blur="points.{{ $point->id }}.impedance" class:input="px-1.5 text-center" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                            :class="$ok === false ? 'ring-2 ring-red-500 rounded-lg' : ($ok === true ? 'ring-1 ring-green-500/60 rounded-lg' : '')" />
                                        <span class="text-end text-sm tabular-nums text-zinc-500">{{ $point->isLineToLine() ? '–' : $fmt($point->shortCircuitCurrent($protocol), 0) }}</span>
                                        @if ($withNpe && ! $board->isSupply())
                                            <flux:input wire:model.blur="points.{{ $point->id }}.impedance_npe" class:input="px-1.5 text-center" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                                :class="$okNpe === false ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                                            <span class="text-end text-sm tabular-nums text-zinc-500">{{ $fmt($point->shortCircuitCurrent($protocol, npe: true), 0) }}</span>
                                        @endif
                                    </div>
                                    @unless ($board->isSupply())
                                        <div class="flex items-center gap-2">
                                            <input type="text" wire:model.blur="points.{{ $point->id }}.location"
                                                class="mt-0.5 min-w-0 flex-1 border-0 bg-transparent p-0 text-xs text-zinc-500 focus:ring-0" />
                                            <flux:button size="xs" :variant="$point->marker ? 'filled' : 'ghost'" icon="map-pin" wire:click="openPlan({{ $point->id }})" :aria-label="__('Mark on the plan')">{{ $point->marker?->number }}</flux:button>
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deletePoint({{ $point->id }})" :aria-label="__('Delete')" />
                                        </div>
                                    @endunless
                                </div>
                            @endforeach
                        </div>

                        @unless ($board->isSupply())
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" icon="plus" wire:click="addPoints({{ $circuit->id }}, 'socket')">{{ __('Socket') }}</flux:button>
                                <flux:button size="sm" icon="plus" wire:click="addPoints({{ $circuit->id }}, 'light')">{{ __('Lighting') }}</flux:button>
                                <flux:button size="sm" icon="plus" wire:click="addPoints({{ $circuit->id }}, 'phases')">L1–L3</flux:button>
                                <flux:button size="sm" icon="plus" wire:click="addPoints({{ $circuit->id }}, 'point')">{{ __('Point') }}</flux:button>
                            </div>

                            {{-- Izolacja obwodu --}}
                            <div class="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
                                <div class="flex flex-wrap items-end justify-between gap-2">
                                    <flux:heading size="sm">{{ __('Insulation resistance') }} [MΩ] · Ra ≥ {{ $fmt(Criteria::requiredInsulation($circuit->insulation_voltage), 1) }}</flux:heading>
                                    <div class="flex items-end gap-2">
                                        <flux:select wire:model.live="circuits.{{ $circuit->id }}.insulation_voltage" size="sm" class="w-24">
                                            @foreach ([250, 500, 1000] as $voltage)
                                                <flux:select.option :value="(string) $voltage">{{ $voltage }} V</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button size="sm" wire:click="fillInsulation({{ $circuit->id }}, '>30')">{{ __('All >30') }}</flux:button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                                    @foreach ($circuit->pairs() as $pair)
                                        @php($pairOk = Criteria::insulationPasses($circuits[$circuit->id]['insulation'][$pair] ?? null, Criteria::requiredInsulation($circuit->insulation_voltage)))
                                        <flux:input wire:model.blur="circuits.{{ $circuit->id }}.insulation.{{ $pair }}" size="sm" :label="$pair" placeholder=">30"
                                            :class="$pairOk === false ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteCircuit({{ $circuit->id }})" wire:confirm="{{ __('Delete this circuit with its results?') }}">{{ __('Delete circuit') }}</flux:button>
                            </div>
                        @endunless
                    </div>
                @endif
            </flux:card>
        @endforeach
    </div>

    @unless ($board->isSupply())
        <flux:button variant="primary" icon="plus" wire:click="addCircuit" class="w-full">{{ __('Add circuit') }}</flux:button>
    @endunless

    {{-- Rzut: stuknij miejsce (nowy numer) albo istniejący numer (gniazdo dołącza do grupy) --}}
    <flux:modal name="plan" class="w-full max-w-5xl" x-on:close="$wire.planPoint = null; $wire.planBoard = false">
        @php($planPointModel = $planPoint ? MeasurementPoint::query()->with('marker')->find($planPoint) : null)
        @php($plan = $planId ? $this->plans->firstWhere('id', $planId) : null)

        <div class="space-y-3"
            x-data="{
                zoom: 100,
                moving: null,
                tap(event) {
                    const rect = this.$refs.image.getBoundingClientRect();
                    const x = (event.clientX - rect.left) / rect.width * 100;
                    const y = (event.clientY - rect.top) / rect.height * 100;
                    if (this.moving !== null) { this.$wire.moveMarker(this.moving, x, y); this.moving = null; return; }
                    if (this.$wire.planBoard) { this.$wire.placeBoardMarker(x, y); return; }
                    this.$wire.placeMarker(x, y);
                },
            }">
            <div>
                <flux:heading size="lg">{{ __('Mark on the plan') }}</flux:heading>
                @if ($planBoard)
                    <flux:text><b>{{ $board->name }}</b> — {{ __('tap the place of the board on the plan') }}</flux:text>
                @elseif ($planPointModel)
                    <flux:text>
                        <b>{{ $planPointModel->symbol }}</b> · {{ $planPointModel->location }}
                        — {{ __('tap the spot for a new number, or an existing number to group sockets next to each other') }}
                    </flux:text>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($this->plans->count() > 1)
                    @foreach ($this->plans as $item)
                        <flux:button size="sm" :variant="$item->id === $planId ? 'primary' : 'outline'" wire:click="showPlan({{ $item->id }})">{{ $item->label() }}</flux:button>
                    @endforeach
                @endif
                <div class="ms-auto flex items-center gap-1">
                    <flux:button size="sm" icon="minus" x-on:click="zoom = Math.max(100, zoom - 50)" :aria-label="__('Zoom out')" />
                    <span class="w-12 text-center text-sm" x-text="zoom + '%'"></span>
                    <flux:button size="sm" icon="plus" x-on:click="zoom = Math.min(400, zoom + 50)" :aria-label="__('Zoom in')" />
                </div>
            </div>

            <div x-show="moving !== null" x-cloak class="rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">
                {{ __('Tap the new position of the marker.') }}
            </div>

            @if ($plan)
                <div class="max-h-[65vh] overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="relative" x-bind:style="'width: ' + zoom + '%'">
                        <img x-ref="image" src="{{ route('attachments.show', ['attachment' => $plan, 'inline' => 1]) }}" alt=""
                            class="block w-full cursor-crosshair select-none" draggable="false" x-on:click="tap($event)">
                        @foreach ($this->planMarkers->filter(fn ($marker) => $marker->isBoard()) as $marker)
                            {{-- Rozdzielnica: czerwony prostokąt z nazwą (stuknięcia przechodzą na rzut) --}}
                            <span wire:key="board-marker-{{ $marker->id }}" style="left: {{ (float) $marker->x }}%; top: {{ (float) $marker->y }}%;"
                                @class([
                                    'pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap border-2 border-zinc-900 bg-red-600 px-2 py-0.5 text-xs font-bold text-white shadow',
                                    'ring-4 ring-blue-300' => $planBoard && $marker->board_id === $board->id,
                                ])>{{ $marker->board?->name }}</span>
                        @endforeach
                        @foreach ($this->planMarkers->reject(fn ($marker) => $marker->isBoard()) as $marker)
                            @php($current = $planPointModel?->marker_id === $marker->id)
                            <button type="button" wire:key="marker-{{ $marker->id }}"
                                wire:click="assignMarker({{ $marker->id }})"
                                title="{{ $marker->points->pluck('symbol')->filter()->implode(', ') }}"
                                style="left: {{ (float) $marker->x }}%; top: {{ (float) $marker->y }}%;"
                                @class([
                                    'absolute flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white text-xs font-bold text-white shadow',
                                    'bg-blue-600 ring-4 ring-blue-300' => $current,
                                    'bg-red-600' => ! $current,
                                ])>
                                {{ $marker->number }}
                                @if ($marker->points->count() > 1)
                                    <span class="absolute -right-2 -top-2 rounded-full bg-zinc-900 px-1 text-[0.6rem] leading-4">×{{ $marker->points->count() }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap justify-between gap-2">
                <div class="flex gap-2">
                    @if ($planBoard && $this->planMarkers->contains('board_id', $board->id))
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBoardMarker">{{ __('Remove from the plan') }}</flux:button>
                    @endif
                    @if ($planPointModel?->marker)
                        <flux:button size="sm" icon="arrows-pointing-out" x-on:click="moving = {{ $planPointModel->marker->id }}">{{ __('Move marker') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="unassignPoint">{{ __('Remove from the plan') }}</flux:button>
                    @endif
                </div>
                <flux:modal.close>
                    <flux:button size="sm" variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <datalist id="in-values">
        @foreach ([6, 10, 13, 16, 20, 25, 32, 40, 50, 63, 80, 100, 125, 160] as $value)
            <option value="{{ $value }}"></option>
        @endforeach
    </datalist>
</section>
