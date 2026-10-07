<?php

use App\Livewire\ComponentWithAttachments;
use App\Models\Contracts\Attachable;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCableTest;
use App\Models\MeasurementContinuity;
use App\Models\MeasurementEarthing;
use App\Models\MeasurementInspection;
use App\Models\MeasurementProtocol;
use App\Services\Measurements\ProtocolCopier;
use App\Support\MeasurementInput;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

new class extends ComponentWithAttachments {
    public MeasurementProtocol $protocol;

    /** @var array<int, string> Wynik oględzin wg id */
    public array $inspections = [];

    /** @var array<int, array{name: string, drawing: string, resistance: string, correction: string, limit: string}> */
    public array $earthings = [];

    /** @var array<int, array{name: string, resistance: string, limit: string}> */
    public array $continuities = [];

    /** @var array<int, array{name: string, cable_type: string, cross_section: string, length: string, temperature: string, test_voltage: string, limit: string, values: array<string, string>}> */
    public array $cables = [];

    public string $newBoard = '';

    public function mount(MeasurementProtocol $protocol): void
    {
        $this->protocol = $protocol;
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $this->inspections = $this->protocol->inspections()->pluck('result', 'id')->all();

        $this->earthings = $this->protocol->earthings()->get()->mapWithKeys(fn (MeasurementEarthing $row) => [$row->id => [
            'name' => $row->name,
            'drawing' => (string) $row->drawing,
            'resistance' => MeasurementInput::show($row->resistance),
            'correction' => MeasurementInput::show($row->correction),
            'limit' => MeasurementInput::show($row->limit),
        ]])->all();

        // Wiersz dla każdego obwodu (do uzupełnienia R) + pomiary dopisane ręcznie.
        $this->protocol->syncContinuities();
        $this->continuities = $this->protocol->continuities()->with('circuit.board.protocol')->get()->mapWithKeys(fn (MeasurementContinuity $row) => [$row->id => [
            'name' => $row->name,
            'resistance' => MeasurementInput::show($row->resistance),
            'limit' => MeasurementInput::show($row->limit),
            'circuit' => $row->circuit_id !== null,
            'auto_limit' => ($auto = $row->automaticLimit()) === null ? '' : MeasurementInput::show(round($auto, 2)),
        ]])->all();

        $this->cables = $this->protocol->cableTests()->get()->mapWithKeys(fn (MeasurementCableTest $row) => [$row->id => [
            'name' => $row->name,
            'cable_type' => (string) $row->cable_type,
            'cross_section' => (string) $row->cross_section,
            'length' => MeasurementInput::show($row->length),
            'temperature' => MeasurementInput::show($row->temperature),
            'test_voltage' => (string) $row->test_voltage,
            'limit' => MeasurementInput::show($row->limit),
            'values' => array_map('strval', $row->values ?? []),
        ]])->all();
    }

    /**
     * @return Collection<int, MeasurementBoard>
     */
    #[Computed]
    public function boards(): Collection
    {
        return $this->protocol->boards()->withCount(['circuits', 'rcds'])->get();
    }

    public function addBoard(string $kind = MeasurementBoard::KIND_BOARD): void
    {
        $this->authorize('manage-measurements');

        $name = trim($this->newBoard) ?: ($kind === MeasurementBoard::KIND_SUPPLY ? 'WLZ' : 'R'.($this->protocol->boards()->where('kind', MeasurementBoard::KIND_BOARD)->count() + 1));
        $board = $this->protocol->boards()->create([
            'position' => (int) $this->protocol->boards()->max('position') + 1,
            'kind' => $kind,
            'name' => mb_substr($name, 0, 100),
        ]);

        // WLZ: od razu zabezpieczenie i odcinki L-N, L-PE, L-L jak w protokole.
        if ($kind === MeasurementBoard::KIND_SUPPLY) {
            $circuit = $board->circuits()->create(['position' => 1, 'name' => __('Supply line'), 'phases' => 3]);

            $position = 0;

            foreach (['L1-N' => 'L-N', 'L2-N' => 'L-N', 'L3-N' => 'L-N', 'L1-PE' => 'L-PE', 'L2-PE' => 'L-PE', 'L3-PE' => 'L-PE', 'L1-L2' => 'L-L', 'L2-L3' => 'L-L', 'L3-L1' => 'L-L'] as $symbol => $loop) {
                $circuit->points()->create(['position' => ++$position, 'symbol' => $symbol, 'loop' => $loop]);
            }
        }

        $this->newBoard = '';
        $this->redirectRoute('measurements.board', [$this->protocol, $board], navigate: true);
    }

    public function updatedInspections(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');

        if (in_array($value, MeasurementInspection::RESULTS, true)) {
            $this->protocol->inspections()->whereKey((int) $key)->update(['result' => $value]);
        }
    }

    public function addEarthing(): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->earthings()->create(['position' => (int) $this->protocol->earthings()->max('position') + 1, 'name' => __('Foundation earth electrode')]);
        $this->loadRows();
    }

    public function updatedEarthings(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        [$id, $field] = explode('.', $key, 2);

        $data = match ($field) {
            'name' => ['name' => mb_substr(trim((string) $value), 0, 255) ?: '—'],
            'drawing' => ['drawing' => trim((string) $value) ?: null],
            'resistance' => ['resistance' => MeasurementInput::decimal($value)],
            'correction' => ['correction' => MeasurementInput::decimal($value) ?? '1'],
            'limit' => ['limit' => MeasurementInput::decimal($value) ?? '10'],
            default => [],
        };

        $this->protocol->earthings()->whereKey((int) $id)->update($data);
    }

    public function addContinuity(): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->continuities()->create(['position' => (int) $this->protocol->continuities()->max('position') + 1, 'name' => __('Main equipotential bonding')]);
        $this->loadRows();
    }

    public function updatedContinuities(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        [$id, $field] = explode('.', $key, 2);

        $data = match ($field) {
            'name' => ['name' => mb_substr(trim((string) $value), 0, 255) ?: '—'],
            'resistance' => ['resistance' => MeasurementInput::decimal($value)],
            'limit' => ['limit' => MeasurementInput::decimal($value)],
            default => [],
        };

        // Nazwy wierszy obwodów idą za obwodem — zmienia się je w rozdzielnicy.
        $this->protocol->continuities()->whereKey((int) $id)
            ->when($field === 'name', fn ($query) => $query->whereNull('circuit_id'))
            ->update($data);
    }

    public function addCable(): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->cableTests()->create(['position' => (int) $this->protocol->cableTests()->max('position') + 1, 'name' => __('Supply line (WLZ)')]);
        $this->loadRows();
    }

    public function updatedCables(mixed $value, string $key): void
    {
        $this->authorize('manage-measurements');
        $parts = explode('.', $key, 3);
        $row = $this->protocol->cableTests()->whereKey((int) $parts[0])->first();

        if ($row === null) {
            return;
        }

        if ($parts[1] === 'values' && isset($parts[2]) && in_array($parts[2], MeasurementCableTest::PAIRS, true)) {
            $values = $row->values ?? [];
            $values[$parts[2]] = MeasurementInput::reading($value);
            $row->update(['values' => array_filter($values, fn ($reading) => $reading !== null)]);

            return;
        }

        $row->update(match ($parts[1]) {
            'name' => ['name' => mb_substr(trim((string) $value), 0, 255) ?: '—'],
            'cable_type' => ['cable_type' => trim((string) $value) ?: null],
            'cross_section' => ['cross_section' => trim((string) $value) ?: null],
            'length' => ['length' => MeasurementInput::decimal($value)],
            'temperature' => ['temperature' => MeasurementInput::decimal($value)],
            'test_voltage' => ['test_voltage' => in_array((int) $value, [250, 500, 1000, 2500], true) ? (int) $value : 1000],
            'limit' => ['limit' => MeasurementInput::decimal($value) ?? '1'],
            default => [],
        });
    }

    /** Wszystkie odcinki kabla „>1000” jednym kliknięciem (typowy wynik). */
    public function fillCable(int $id, string $reading): void
    {
        $this->authorize('manage-measurements');
        $row = $this->protocol->cableTests()->whereKey($id)->first();
        $row?->update(['values' => array_fill_keys(MeasurementCableTest::PAIRS, MeasurementInput::reading($reading))]);
        $this->loadRows();
    }

    public function deleteRow(string $type, int $id): void
    {
        $this->authorize('manage-measurements');

        match ($type) {
            'earthing' => $this->protocol->earthings()->whereKey($id)->delete(),
            'continuity' => $this->protocol->continuities()->whereKey($id)->whereNull('circuit_id')->delete(),
            'cable' => $this->protocol->cableTests()->whereKey($id)->delete(),
            default => null,
        };

        $this->loadRows();
    }

    /** Nowy protokół tego obiektu: ta sama struktura (rozdzielnice, obwody, punkty), bez wyników. */
    public function copyForNextTest(ProtocolCopier $copier): void
    {
        $this->authorize('manage-measurements');
        $copy = $copier->copy($this->protocol, auth()->user());

        Flux::toast(variant: 'success', text: __('New protocol :number created from this one.', ['number' => $copy->number]));
        $this->redirectRoute('measurements.edit', $copy, navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('manage-measurements');
        $this->protocol->delete();

        Flux::toast(variant: 'success', text: __('Protocol deleted.'));
        $this->redirectRoute('measurements.index', navigate: true);
    }

    protected function attachmentOwner(): (Model&Attachable)|null
    {
        return $this->protocol;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-measurements';
    }

    public function render()
    {
        return $this->view()->title($this->protocol->number);
    }
}; ?>

@php
    use App\Services\Measurements\Criteria;
@endphp

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ $protocol->number }}</flux:heading>
            <flux:subheading>
                <flux:link :href="route('measurements.index')" wire:navigate>{{ __('Measurements') }}</flux:link>
                · {{ $protocol->place }} · {{ $protocol->measured_on->format('d.m.Y') }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="pencil-square" :href="route('measurements.edit', $protocol)" wire:navigate>{{ __('Protocol details') }}</flux:button>
            <flux:button variant="primary" icon="document-arrow-down" :href="route('measurements.report', $protocol)" target="_blank">{{ __('Report PDF') }}</flux:button>
            <flux:dropdown>
                <flux:button icon="ellipsis-vertical" :aria-label="__('More')" />
                <flux:menu>
                    <flux:menu.item icon="document-duplicate" wire:click="copyForNextTest"
                        wire:confirm="{{ __('Create a new protocol for the next test with the same boards, circuits and points (without results)?') }}">
                        {{ __('New protocol based on this one') }}
                    </flux:menu.item>
                    <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this protocol with all results?') }}">
                        {{ __('Delete') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    {{-- Rozdzielnice --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Boards') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:input wire:model="newBoard" size="sm" :placeholder="__('Name, e.g. R1')" class="w-32" />
                <flux:button size="sm" icon="plus" wire:click="addBoard('board')">{{ __('Board') }}</flux:button>
                <flux:button size="sm" icon="plus" wire:click="addBoard('supply')">{{ __('Supply line (WLZ)') }}</flux:button>
            </div>
        </div>

        @if ($this->boards->isEmpty())
            <flux:text>{{ __('Add the first board (e.g. R1) — then circuits and measuring points.') }}</flux:text>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->boards as $board)
                    <a href="{{ route('measurements.board', [$protocol, $board]) }}" wire:navigate wire:key="board-{{ $board->id }}"
                        class="block rounded-lg border border-zinc-200 p-4 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-lg font-semibold">{{ $board->name }}</span>
                            <flux:icon.chevron-right class="size-5 text-zinc-400" />
                        </div>
                        <div class="text-sm text-zinc-500">
                            @if ($board->isSupply())
                                {{ __('Supply line') }}
                            @else
                                {{ trans_choice(':count circuit|:count circuits', $board->circuits_count, ['count' => $board->circuits_count]) }}
                                · {{ trans_choice(':count RCD|:count RCDs', $board->rcds_count, ['count' => $board->rcds_count]) }}
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Oględziny --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg">{{ __('Visual inspection') }}</flux:heading>
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($protocol->inspections()->get() as $inspection)
                <div wire:key="inspection-{{ $inspection->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <div class="min-w-0 flex-1 text-sm">
                        {{ $inspection->item }}
                        <div class="text-xs text-zinc-500">{{ $inspection->standard }}</div>
                    </div>
                    <flux:select wire:model.live="inspections.{{ $inspection->id }}" size="sm" class="w-36">
                        <flux:select.option value="compliant">{{ __('compliant') }}</flux:select.option>
                        <flux:select.option value="non_compliant">{{ __('non-compliant') }}</flux:select.option>
                        <flux:select.option value="not_applicable">{{ __('not applicable') }}</flux:select.option>
                    </flux:select>
                </div>
            @endforeach
        </div>
    </flux:card>

    {{-- Izolacja kabli (WLZ, podrozdzielnice) --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Cable insulation (WLZ, sub-boards)') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addCable">{{ __('Cable') }}</flux:button>
        </div>

        @foreach ($cables as $id => $cable)
            <div wire:key="cable-{{ $id }}" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_7rem_6rem_5rem_5rem_6rem_auto]">
                    <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.name" size="sm" :label="__('Section')" class="col-span-2 sm:col-span-1" />
                    <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.cable_type" size="sm" :label="__('Cable')" placeholder="YKY" />
                    <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.cross_section" size="sm" :label="__('Cross-section')" placeholder="5x16" />
                    <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.length" size="sm" :label="__('l [m]')" inputmode="decimal" />
                    <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.temperature" size="sm" :label="__('t [°C]')" inputmode="decimal" />
                    <flux:select wire:model.live="cables.{{ $id }}.test_voltage" size="sm" :label="__('Uiso [V]')">
                        @foreach ([250, 500, 1000, 2500] as $voltage)
                            <flux:select.option :value="(string) $voltage">{{ $voltage }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex items-end">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRow('cable', {{ $id }})" wire:confirm="{{ __('Delete this row?') }}" :aria-label="__('Delete')" />
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                    @foreach (\App\Models\MeasurementCableTest::PAIRS as $pair)
                        @php($ok = Criteria::insulationPasses($cable['values'][$pair] ?? null, (float) str_replace(',', '.', $cable['limit'] ?: '1')))
                        <flux:input wire:model.live.debounce.700ms="cables.{{ $id }}.values.{{ $pair }}" size="sm" :label="$pair . ' [MΩ]'" placeholder=">1000"
                            :class="$ok === false ? 'ring-2 ring-red-500 rounded-lg' : ''" />
                    @endforeach
                </div>
                <flux:button size="xs" wire:click="fillCable({{ $id }}, '>1000')">{{ __('All >1000') }}</flux:button>
            </div>
        @endforeach
    </flux:card>

    {{-- Uziemienie --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Earthing resistance') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addEarthing">{{ __('Earthing') }}</flux:button>
        </div>
        @foreach ($earthings as $id => $earthing)
            @php($re = MeasurementInput::decimal($earthing['resistance']))
            @php($kp = (float) (MeasurementInput::decimal($earthing['correction']) ?? 1))
            @php($ra = (float) (MeasurementInput::decimal($earthing['limit']) ?? 10))
            <div wire:key="earthing-{{ $id }}" class="grid grid-cols-2 items-end gap-2 sm:grid-cols-[1fr_5rem_6rem_5rem_5rem_6rem_auto]">
                <flux:input wire:model.live.debounce.700ms="earthings.{{ $id }}.name" size="sm" :label="__('Tested point')" class="col-span-2 sm:col-span-1" />
                <flux:input wire:model.live.debounce.700ms="earthings.{{ $id }}.drawing" size="sm" :label="__('Drawing')" />
                <flux:input wire:model.live.debounce.700ms="earthings.{{ $id }}.resistance" size="sm" :label="__('RE [Ω]')" inputmode="decimal" />
                <flux:input wire:model.live.debounce.700ms="earthings.{{ $id }}.correction" size="sm" :label="__('Kp')" inputmode="decimal" />
                <flux:input wire:model.live.debounce.700ms="earthings.{{ $id }}.limit" size="sm" :label="__('Ra [Ω]')" inputmode="decimal" />
                <div class="pb-1 text-sm">
                    {{ $re !== null ? MeasurementInput::format((float) $re * $kp) . ' Ω' : '' }}
                    <x-measure-verdict :passes="Criteria::earthingPasses($re !== null ? (float) $re : null, $kp, $ra)" />
                </div>
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRow('earthing', {{ $id }})" wire:confirm="{{ __('Delete this row?') }}" :aria-label="__('Delete')" />
            </div>
        @endforeach
    </flux:card>

    {{-- Ciągłość --}}
    <flux:card class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Continuity of protective conductors') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addContinuity">{{ __('Measurement') }}</flux:button>
        </div>
        @foreach ($continuities as $id => $continuity)
            @php($r = MeasurementInput::decimal($continuity['resistance']))
            @php($limit = MeasurementInput::decimal($continuity['limit']))
            @php($autoLimit = MeasurementInput::decimal($continuity['auto_limit']))
            @php($effectiveLimit = $limit ?? $autoLimit)
            <div wire:key="continuity-{{ $id }}" class="grid grid-cols-2 items-end gap-2 sm:grid-cols-[1fr_6rem_6rem_6rem_2.25rem]">
                @if ($continuity['circuit'])
                    {{-- Wiersz obwodu: nazwa z rozdzielnicy, limit UL/Ia (można wpisać inny) --}}
                    <flux:field class="col-span-2 sm:col-span-1">
                        <flux:label>{{ __('Circuit') }}</flux:label>
                        <div class="truncate py-1.5 text-sm">{{ $continuity['name'] }}</div>
                    </flux:field>
                @else
                    <flux:input wire:model.live.debounce.700ms="continuities.{{ $id }}.name" size="sm" :label="__('Tested connection')" class="col-span-2 sm:col-span-1" />
                @endif
                <flux:input wire:model.live.debounce.700ms="continuities.{{ $id }}.resistance" size="sm" :label="__('R [Ω]')" inputmode="decimal" />
                <flux:input wire:model.live.debounce.700ms="continuities.{{ $id }}.limit" size="sm" :label="__('Limit [Ω]')" inputmode="decimal" :placeholder="$continuity['auto_limit'] !== '' ? $continuity['auto_limit'] : null" />
                <div class="pb-1"><x-measure-verdict :passes="Criteria::continuityPasses($r !== null ? (float) $r : null, $effectiveLimit !== null ? (float) $effectiveLimit : null)" /></div>
                @unless ($continuity['circuit'])
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRow('continuity', {{ $id }})" wire:confirm="{{ __('Delete this row?') }}" :aria-label="__('Delete')" />
                @endunless
            </div>
        @endforeach
    </flux:card>

    <x-attachments :owner="$protocol" :uploads="$uploads" :heading="__('Drawings and attachments')" />
</section>
