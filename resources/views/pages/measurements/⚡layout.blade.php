<?php

use App\Models\MeasurementBoard;
use App\Models\MeasurementProtocol;
use App\Services\Measurements\BoardLayout;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public MeasurementProtocol $protocol;

    public MeasurementBoard $board;

    /** @var array{rail: int, report: bool, rows: list<list<array{t: string, id?: int|null, w: int, label?: string|null, desc?: string|null}>>} */
    public array $layout = ['rail' => 12, 'report' => true, 'rows' => [[]]];

    /** Zaznaczony moduł: [szyna, pozycja]. */
    public ?int $row = null;

    public ?int $col = null;

    public string $deviceLabel = '';

    public string $deviceDesc = '';

    public function mount(MeasurementProtocol $protocol, MeasurementBoard $board): void
    {
        abort_unless($board->protocol_id === $protocol->id, 404);

        $this->protocol = $protocol;
        $this->board = $board;
        $this->layout = BoardLayout::for($board)->toArray();

        // Pierwsze otwarcie: elewacja trafia do raportu.
        if ($board->layout === null) {
            $this->layout['report'] = true;
            $this->persist();
        }
    }

    public function select(int $row, int $col): void
    {
        [$this->row, $this->col] = $this->row === $row && $this->col === $col ? [null, null] : [$row, $col];
        $item = $this->selected();
        $this->deviceLabel = (string) ($item['label'] ?? '');
        $this->deviceDesc = (string) ($item['desc'] ?? '');
    }

    /** Przesunięcie w szynie (-1 w lewo, 1 w prawo). */
    public function shift(int $direction): void
    {
        if (($item = $this->selected()) === null) {
            return;
        }

        $row = &$this->layout['rows'][$this->row];
        $target = $this->col + $direction;

        if ($target < 0 || $target >= count($row)) {
            return;
        }

        [$row[$this->col], $row[$target]] = [$row[$target], $item];
        $this->col = $target;
        $this->persist();
    }

    /** Przeniesienie na sąsiednią szynę (-1 wyżej, 1 niżej) — na jej koniec. */
    public function moveRow(int $direction): void
    {
        if (($item = $this->selected()) === null) {
            return;
        }

        $target = $this->row + $direction;

        if ($target < 0 || $target >= count($this->layout['rows'])) {
            return;
        }

        array_splice($this->layout['rows'][$this->row], $this->col, 1);
        $this->layout['rows'][$target][] = $item;
        [$this->row, $this->col] = [$target, count($this->layout['rows'][$target]) - 1];
        $this->persist();
    }

    public function resize(int $delta): void
    {
        if ($this->selected() === null) {
            return;
        }

        $width = (int) $this->layout['rows'][$this->row][$this->col]['w'] + $delta;
        $this->layout['rows'][$this->row][$this->col]['w'] = max(1, min(8, $width));
        $this->persist();
    }

    /** Opis i oznaczenie aparatu dodanego ręcznie (F0, WG…). */
    public function saveDevice(): void
    {
        $item = $this->selected();

        if ($item === null || $item['t'] !== 'device') {
            return;
        }

        $this->layout['rows'][$this->row][$this->col]['label'] = mb_substr(trim($this->deviceLabel), 0, 20);
        $this->layout['rows'][$this->row][$this->col]['desc'] = mb_substr(trim($this->deviceDesc), 0, 80);
        $this->persist();
    }

    /** Usuwa aparat lub puste miejsce (RCD i obwody zostają — są częścią pomiarów). */
    public function removeSelected(): void
    {
        $item = $this->selected();

        if ($item === null || ! in_array($item['t'], ['device', 'gap'], true)) {
            return;
        }

        array_splice($this->layout['rows'][$this->row], $this->col, 1);
        [$this->row, $this->col] = [null, null];
        $this->persist();
    }

    /**
     * Dodaje aparat (F0, WG, SPD…), własny aparat albo puste miejsce na końcu zaznaczonej (lub ostatniej) szyny.
     */
    public function addItem(string $kind, string $label = ''): void
    {
        $row = $this->row ?? array_key_last($this->layout['rows']) ?? 0;

        $item = match ($kind) {
            'gap' => ['t' => 'gap', 'w' => 1],
            default => ['t' => 'device', 'w' => in_array($label, ['F0', 'WG'], true) ? 3 : ($label === 'SPD' ? 4 : 1), 'label' => $label ?: 'Q', 'desc' => BoardLayout::DEVICES[$label] ?? ''],
        };

        $this->layout['rows'][$row][] = $item;
        [$this->row, $this->col] = [$row, count($this->layout['rows'][$row]) - 1];
        $this->deviceLabel = (string) ($item['label'] ?? '');
        $this->deviceDesc = (string) ($item['desc'] ?? '');
        $this->persist();
    }

    public function addRow(): void
    {
        $this->layout['rows'][] = [];
        $this->persist();
    }

    public function removeRow(int $row): void
    {
        $items = $this->layout['rows'][$row] ?? [];

        // Pustą szynę usuwamy; RCD i obwody z usuwanej szyny przechodzą na poprzednią.
        $keep = array_values(array_filter($items, fn (array $item) => in_array($item['t'], ['rcd', 'circuit'], true)));
        array_splice($this->layout['rows'], $row, 1);

        if ($this->layout['rows'] === []) {
            $this->layout['rows'] = [[]];
        }

        if ($keep !== []) {
            $target = max(0, $row - 1);
            array_push($this->layout['rows'][$target], ...$keep);
        }

        [$this->row, $this->col] = [null, null];
        $this->persist();
    }

    public function moveRowUp(int $row): void
    {
        if ($row > 0) {
            [$this->layout['rows'][$row - 1], $this->layout['rows'][$row]] = [$this->layout['rows'][$row], $this->layout['rows'][$row - 1]];
            [$this->row, $this->col] = [null, null];
            $this->persist();
        }
    }

    public function updatedLayout(): void
    {
        $this->persist();
    }

    public function autoLayout(): void
    {
        $this->layout = BoardLayout::auto($this->board, report: (bool) $this->layout['report'])->toArray();
        [$this->row, $this->col] = [null, null];
        $this->persist();
        Flux::toast(text: __('Arranged from RCDs and circuits.'));
    }

    /**
     * @return array{t: string, id?: int|null, w: int, label?: string|null, desc?: string|null}|null
     */
    private function selected(): ?array
    {
        return $this->row !== null && $this->col !== null ? ($this->layout['rows'][$this->row][$this->col] ?? null) : null;
    }

    private function persist(): void
    {
        $this->authorize('manage-measurements');
        $this->board->update(['layout' => $this->layout]);
        $this->board->refresh();
        $this->layout = BoardLayout::for($this->board)->toArray();
    }

    public function render()
    {
        return $this->view()->title(__('Board elevation').' · '.$this->board->name);
    }
}; ?>

@php
    $resolved = BoardLayout::for($board)->resolved();
    $selected = $row !== null && $col !== null ? ($layout['rows'][$row][$col] ?? null) : null;
@endphp

<section class="w-full max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('measurements.board', [$protocol, $board])" wire:navigate :aria-label="__('Back')" />
            <div class="min-w-0">
                <flux:heading size="lg">{{ __('Board elevation') }} · {{ $board->name }}</flux:heading>
                <flux:text class="truncate">{{ $protocol->number }} · {{ $protocol->place }}</flux:text>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model.live="layout.rail" size="sm" class="w-36">
                @foreach (BoardLayout::RAILS as $rail)
                    <flux:select.option :value="$rail">{{ __(':count modules', ['count' => $rail]) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model.live="layout.report" :label="__('In the report')" />
            <flux:button size="sm" variant="primary" icon="printer" :href="route('measurements.legend', [$protocol, $board])" target="_blank">{{ __('Legend for the board') }}</flux:button>
        </div>
    </div>

    {{-- Szyny: moduły w skali, pionowy opis nad modułem --}}
    <flux:card class="space-y-6 overflow-x-auto">
        @foreach ($resolved as $r => $items)
            @php($used = array_sum(array_column($items, 'w')))
            <div wire:key="row-{{ $r }}-{{ count($items) }}" class="space-y-1">
                <div class="flex items-center justify-between gap-2 text-xs text-zinc-500">
                    <span>{{ __('Rail :number', ['number' => $r + 1]) }} · {{ $used }}/{{ $layout['rail'] }}</span>
                    <span class="flex gap-1">
                        @if ($r > 0)
                            <flux:button size="xs" variant="ghost" icon="arrow-up" wire:click="moveRowUp({{ $r }})" :aria-label="__('Move rail up')" />
                        @endif
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeRow({{ $r }})" :aria-label="__('Remove rail')" />
                    </span>
                </div>
                <div class="flex" style="min-width: {{ max($layout['rail'], $used) * 2.25 }}rem;">
                    @foreach ($items as $c => $item)
                        @php($isSelected = $row === $r && $col === $c)
                        <button type="button" wire:click="select({{ $r }}, {{ $c }})" wire:key="item-{{ $r }}-{{ $c }}"
                            class="flex shrink-0 flex-col items-stretch" style="width: {{ $item['w'] * 2.25 }}rem;">
                            <span class="flex h-28 items-end justify-center overflow-hidden px-0.5 pb-1">
                                <span class="text-[0.6rem] leading-tight text-zinc-600 [writing-mode:vertical-rl] rotate-180 dark:text-zinc-300">{{ $item['desc'] }}</span>
                            </span>
                            {{-- Moduł jak aparat: numer, dźwigienka, zabezpieczenie --}}
                            <span @class([
                                'flex h-24 flex-col items-center justify-between rounded-[3px] border py-1.5 text-xs font-semibold shadow-sm',
                                'border-zinc-500 bg-zinc-300 text-zinc-900' => in_array($item['t'], ['rcd', 'device'], true),
                                'border-zinc-500 bg-white text-zinc-900' => $item['t'] === 'circuit',
                                'border-dashed border-zinc-400 bg-transparent shadow-none' => $item['t'] === 'gap',
                                'ring-4 ring-blue-500 relative z-10' => $isSelected,
                            ])>
                                @if ($item['t'] !== 'gap')
                                    <span class="leading-none">{{ $item['label'] }}</span>
                                    <span class="h-7 w-2.5 rounded-sm bg-zinc-700"></span>
                                    <span class="text-[0.55rem] font-normal leading-none">{{ $item['sub'] }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                    @for ($i = $used; $i < $layout['rail']; $i++)
                        <span class="flex shrink-0 flex-col" style="width: 2.25rem;">
                            <span class="h-28"></span>
                            <span class="h-24 rounded-[3px] border border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-white/5"></span>
                        </span>
                    @endfor
                </div>
            </div>
        @endforeach
    </flux:card>

    {{-- Narzędzia zaznaczonego modułu --}}
    @if ($selected)
        <flux:card class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <flux:button size="sm" icon="chevron-left" wire:click="shift(-1)" :aria-label="__('Move left')" />
                <flux:button size="sm" icon="chevron-right" wire:click="shift(1)" :aria-label="__('Move right')" />
                <flux:button size="sm" icon="chevron-up" wire:click="moveRow(-1)" :aria-label="__('Rail above')" />
                <flux:button size="sm" icon="chevron-down" wire:click="moveRow(1)" :aria-label="__('Rail below')" />
                <span class="mx-2 text-sm text-zinc-500">{{ __('Width') }}</span>
                <flux:button size="sm" icon="minus" wire:click="resize(-1)" :aria-label="__('Narrower')" />
                <span class="w-6 text-center text-sm">{{ $selected['w'] }}</span>
                <flux:button size="sm" icon="plus" wire:click="resize(1)" :aria-label="__('Wider')" />
                @if (in_array($selected['t'], ['device', 'gap'], true))
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSelected" class="ms-auto">{{ __('Delete') }}</flux:button>
                @endif
            </div>
            @if ($selected['t'] === 'device')
                <div class="grid grid-cols-[6rem_1fr] gap-2">
                    <flux:input wire:model.blur="deviceLabel" wire:change="saveDevice" size="sm" :label="__('Symbol')" />
                    <flux:input wire:model.blur="deviceDesc" wire:change="saveDevice" size="sm" :label="__('Description')" />
                </div>
            @elseif ($selected['t'] === 'circuit')
                <flux:text size="sm">{{ __('The description is the circuit name — change it on the board page.') }}</flux:text>
            @endif
        </flux:card>
    @endif

    <div class="flex flex-wrap gap-2">
        @foreach (BoardLayout::DEVICES as $symbol => $description)
            <flux:button size="sm" icon="plus" wire:click="addItem('device', '{{ $symbol }}')">{{ $symbol }}</flux:button>
        @endforeach
        <flux:button size="sm" icon="plus" wire:click="addItem('device')">{{ __('Device') }}</flux:button>
        <flux:button size="sm" icon="plus" wire:click="addItem('gap')">{{ __('Empty space') }}</flux:button>
        <flux:button size="sm" icon="plus" wire:click="addRow">{{ __('Rail') }}</flux:button>
        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="autoLayout" wire:confirm="{{ __('Arrange the elevation again from RCDs and circuits? Added devices will be removed.') }}" class="ms-auto">{{ __('Arrange automatically') }}</flux:button>
    </div>
</section>
