<?php

use App\Enums\ProtectionType;
use App\Enums\RcdType;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementPoint;
use App\Models\MeasurementProtocol;
use App\Models\MeasurementRcd;
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
            'trip_current_override' => MeasurementInput::show($circuit->trip_current_override),
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
        return $this->board->circuits()->with(['points', 'rcd'])->get();
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

        $this->board->rcds()->create([
            'position' => ($last->position ?? 0) + 1,
            'designation' => 'Fi'.(($last->position ?? 0) + 1),
            'model' => $last?->model,
            'type' => $last->type ?? RcdType::A,
            'rated_current' => $last?->rated_current,
            'rated_residual' => $last->rated_residual ?? 30,
        ]);

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
                'trip_current_override' => ['trip_current_override' => MeasurementInput::decimal($value)],
                'cable' => ['cable' => trim((string) $value) ?: null],
                'rcd_id' => ['rcd_id' => $value !== '' && $this->board->rcds()->whereKey((int) $value)->exists() ? (int) $value : null],
                'insulation_voltage' => ['insulation_voltage' => in_array((int) $value, [250, 500, 1000], true) ? (int) $value : 500],
                default => [],
            });
        }

        unset($this->circuitModels);
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
        $this->refreshState();
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
    use App\Services\Measurements\Criteria;

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
                <div wire:key="rcd-{{ $rcd->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-[5rem_1fr_5rem_5rem_5rem_auto]">
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.designation" size="sm" :label="__('Symbol')" />
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.model" size="sm" :label="__('Device')" placeholder="PXF 40/4/003-A" class="col-span-2 sm:col-span-1" />
                        <flux:select wire:model.live="rcds.{{ $rcd->id }}.type" size="sm" :label="__('Type')">
                            @foreach (RcdType::cases() as $type)
                                <flux:select.option :value="$type->value">{{ $type->value }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.rated_current" size="sm" :label="__('In [A]')" inputmode="decimal" />
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.rated_residual" size="sm" :label="__('IΔn [mA]')" inputmode="numeric" />
                        <div class="flex items-end justify-end">
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRcd({{ $rcd->id }})" wire:confirm="{{ __('Delete this RCD?') }}" :aria-label="__('Delete')" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 items-end gap-2 sm:grid-cols-[6rem_6rem_6rem_auto_auto_1fr]">
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.trip_time" size="sm" :label="__('t [ms]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                            :class="in_array('time', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.trip_current" size="sm" :label="__('Ia [mA]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                            :class="in_array('current', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                        <flux:input wire:model.blur="rcds.{{ $rcd->id }}.contact_voltage" size="sm" :label="__('Ud [V]')" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                            :class="in_array('contact_voltage', $failures ?? [], true) ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                        <flux:checkbox wire:model.live="rcds.{{ $rcd->id }}.selective" :label="__('S (selective)')" />
                        <flux:checkbox wire:model.live="rcds.{{ $rcd->id }}.test_button" :label="__('TEST OK')" />
                        <div class="flex justify-end"><x-measure-verdict :passes="$failures === null ? null : $failures === []" /></div>
                    </div>
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

                        {{-- Zabezpieczenie: typ jednym kliknięciem, In, RCD --}}
                        <div class="flex flex-wrap items-end gap-2">
                            <flux:field>
                                <flux:label>{{ __('Protection') }}</flux:label>
                                <div class="flex gap-1">
                                    @foreach (ProtectionType::cases() as $type)
                                        <flux:button size="sm" :variant="$circuit->protection_type === $type ? 'primary' : 'outline'"
                                            wire:click="$set('circuits.{{ $circuit->id }}.protection_type', '{{ $type->value }}')">{{ $type->label() }}</flux:button>
                                    @endforeach
                                </div>
                            </flux:field>
                            <flux:input wire:model.blur="circuits.{{ $circuit->id }}.protection_current" size="sm" :label="__('In [A]')" inputmode="decimal" class="w-20" list="in-values" />
                            <flux:input wire:model.blur="circuits.{{ $circuit->id }}.trip_current_override" size="sm" :label="__('Ia manually [A]')" inputmode="decimal" class="w-28"
                                :placeholder="$ia !== null ? $fmt($ia, 0) : ''" />
                            @unless ($board->isSupply())
                                <flux:select wire:model.live="circuits.{{ $circuit->id }}.rcd_id" size="sm" :label="__('RCD')" class="w-28">
                                    <flux:select.option value="">—</flux:select.option>
                                    @foreach ($this->rcdModels as $rcd)
                                        <flux:select.option :value="(string) $rcd->id">{{ $rcd->designation }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endunless
                            <div class="pb-1 text-sm text-zinc-500">
                                Ia {{ $fmt($ia, 0) }} A · Za {{ $fmt($za) }} Ω
                                @if ($ia === null && $circuit->protection_type === ProtectionType::GG)
                                    <span class="text-amber-600">· {{ __('enter Ia from the fuse catalogue') }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Punkty --}}
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-start text-xs text-zinc-500">
                                        <th class="w-16 py-1 text-start font-normal">{{ __('Symbol') }}</th>
                                        @unless ($board->isSupply())
                                            <th class="py-1 text-start font-normal">{{ __('Tested point') }}</th>
                                        @endunless
                                        <th class="w-24 py-1 text-start font-normal">Zs {{ $board->isSupply() ? '' : 'L-PE' }} [Ω]</th>
                                        <th class="w-14 py-1 text-end font-normal">Ik [A]</th>
                                        @if ($withNpe && ! $board->isSupply())
                                            <th class="w-24 py-1 ps-3 text-start font-normal">Zs N-PE [Ω]</th>
                                            <th class="w-14 py-1 text-end font-normal">Ik [A]</th>
                                        @endif
                                        <th class="w-24"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($circuit->points as $point)
                                        @php($ok = $point->passes($za))
                                        @php($okNpe = $point->passes($za, npe: true))
                                        <tr wire:key="point-{{ $point->id }}" class="border-t border-zinc-100 dark:border-zinc-800">
                                            <td class="py-1 pe-2">
                                                @if ($board->isSupply())
                                                    <span class="font-medium">{{ $point->symbol }}</span>
                                                @else
                                                    <flux:input wire:model.blur="points.{{ $point->id }}.symbol" size="sm" />
                                                @endif
                                            </td>
                                            @unless ($board->isSupply())
                                                <td class="py-1 pe-2"><flux:input wire:model.blur="points.{{ $point->id }}.location" size="sm" /></td>
                                            @endunless
                                            <td class="py-1 pe-2">
                                                <flux:input wire:model.blur="points.{{ $point->id }}.impedance" size="sm" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                                    :class="$ok === false ? 'ring-2 ring-red-500 rounded-lg' : ($ok === true ? 'ring-1 ring-green-500/60 rounded-lg' : '')" />
                                            </td>
                                            <td class="py-1 text-end tabular-nums text-zinc-500">{{ $point->isLineToLine() ? '–' : $fmt($point->shortCircuitCurrent($protocol), 0) }}</td>
                                            @if ($withNpe && ! $board->isSupply())
                                                <td class="py-1 ps-3 pe-2">
                                                    <flux:input wire:model.blur="points.{{ $point->id }}.impedance_npe" size="sm" inputmode="decimal" data-measure x-on:keydown.enter.prevent="next($event)"
                                                        :class="$okNpe === false ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                                                </td>
                                                <td class="py-1 text-end tabular-nums text-zinc-500">{{ $fmt($point->shortCircuitCurrent($protocol, npe: true), 0) }}</td>
                                            @endif
                                            <td class="py-1 text-end">
                                                @unless ($board->isSupply())
                                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deletePoint({{ $point->id }})" :aria-label="__('Delete')" />
                                                @endunless
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
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

    <datalist id="in-values">
        @foreach ([6, 10, 13, 16, 20, 25, 32, 40, 50, 63, 80, 100, 125, 160] as $value)
            <option value="{{ $value }}"></option>
        @endforeach
    </datalist>
</section>
