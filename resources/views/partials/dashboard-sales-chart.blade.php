{{--
    Sprzedaż i zakupy netto (PLN) w ostatnich 12 miesiącach — słupki obok siebie, jedna oś.
    Kolory: paleta referencyjna (slot 1 niebieski = sprzedaż, slot 2 pomarańczowy = zakupy), osobne odcienie w trybie ciemnym.
    Po najechaniu na miesiąc — podpowiedź z kwotami; pod wykresem tabela z tymi samymi danymi.
--}}
@php
    $months = $data['months'];
    $max = max(1, ...array_map(fn ($month) => max($month['sales'], $month['purchases']), $months));
    // „Ładny” koniec osi: 1, 2, 2,5 albo 5 × potęga dziesięciu.
    $magnitude = 10 ** floor(log10($max));
    $top = collect([1, 2, 2.5, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($value) => $value >= $max);
    [$width, $height, $left, $right, $plotTop, $bottom] = [720, 240, 56, 8, 12, 26];
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $plotTop - $bottom;
    $group = $plotWidth / count($months);
    $bar = min(16, $group * 0.32);
    $y = fn (float $value) => $plotTop + $plotHeight - $value / $top * $plotHeight;
    $short = fn (float $value) => $value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 1, ',', ' '), '0'), ',').' tys.' : number_format($value, 0, ',', ' ');
    $pln = fn (float $value) => number_format($value, 2, ',', ' ').' zł';
    // Słupek z zaokrągloną górą (4 px), przyklejony do osi.
    $barPath = function (float $x, float $value) use ($y, $plotTop, $plotHeight, $bar) {
        $base = $plotTop + $plotHeight;
        $topY = $y($value);
        $r = min(4, $bar / 2, max(0, $base - $topY));

        return sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
            $x, $base, $topY + $r, $x, $topY, $x + $r, $topY, $x + $bar - $r, $x + $bar, $topY, $x + $bar, $topY + $r, $base);
    };
@endphp

@php($tips = array_map(fn ($month) => [
    'label' => $month['label'],
    'sales' => $pln($month['sales']),
    'purchases' => $pln($month['purchases']),
    'difference' => $pln($month['sales'] - $month['purchases']),
], $months))

<flux:card class="space-y-4" x-data="{ i: null, tips: @js($tips) }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Sales and purchases') }}</flux:heading>
            <flux:text size="sm">{{ __('Net in PLN, last 12 months') }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-6">
            <div>
                <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300"><span class="size-3 rounded-sm bg-[#2a78d6] dark:bg-[#3987e5]"></span>{{ __('Sales') }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $pln($data['sales']) }}</div>
            </div>
            <div>
                <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300"><span class="size-3 rounded-sm bg-[#eb6834] dark:bg-[#d95926]"></span>{{ __('Purchases') }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $pln($data['purchases']) }}</div>
            </div>
            <div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Difference') }}</div>
                <div @class(['text-xl font-semibold tabular-nums', 'text-red-600 dark:text-red-400' => $data['sales'] < $data['purchases']])>{{ $pln($data['sales'] - $data['purchases']) }}</div>
            </div>
        </div>
    </div>

    <div class="relative">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="block h-auto w-full" role="img" aria-label="{{ __('Sales and purchases') }}">
            {{-- Siatka i oś Y — wyciszone --}}
            @foreach ([0, 0.25, 0.5, 0.75, 1] as $step)
                @php($lineY = $y($top * $step))
                <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $lineY }}" y2="{{ $lineY }}" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="1" />
                <text x="{{ $left - 8 }}" y="{{ $lineY + 4 }}" text-anchor="end" class="fill-zinc-500 dark:fill-zinc-400" font-size="11">{{ $short($top * $step) }}</text>
            @endforeach

            @foreach ($months as $index => $month)
                @php($x = $left + $index * $group + ($group - 2 * $bar - 2) / 2)
                <g>
                    @if ($month['sales'] > 0)
                        <path d="{{ $barPath($x, $month['sales']) }}" class="fill-[#2a78d6] dark:fill-[#3987e5]" />
                    @endif
                    @if ($month['purchases'] > 0)
                        <path d="{{ $barPath($x + $bar + 2, $month['purchases']) }}" class="fill-[#eb6834] dark:fill-[#d95926]" />
                    @endif
                    <text x="{{ $left + $index * $group + $group / 2 }}" y="{{ $height - 8 }}" text-anchor="middle" class="fill-zinc-500 dark:fill-zinc-400" font-size="11">{{ $month['label'] }}</text>
                    {{-- Pole najechania większe od słupków: cały miesiąc --}}
                    <rect x="{{ $left + $index * $group }}" y="{{ $plotTop }}" width="{{ $group }}" height="{{ $plotHeight }}" fill="transparent"
                        x-on:mouseenter="i = {{ $index }}" x-on:mouseleave="i = null" x-bind:class="i === {{ $index }} ? 'fill-zinc-500/10' : ''" />
                </g>
            @endforeach
        </svg>

        {{-- Podpowiedź: miesiąc, sprzedaż, zakupy, różnica (jeden element, treść z danych miesiąca) --}}
        <div x-bind:class="i === null ? 'hidden' : ''" x-bind:style="i === null ? '' : 'left: ' + Math.min(88, Math.max(12, ({{ $left }} + (i + 0.5) * {{ $group }}) / {{ $width }} * 100)) + '%'"
            x-cloak class="pointer-events-none absolute top-2 z-10 w-48 -translate-x-1/2 rounded-lg border border-zinc-200 bg-white p-2 text-xs shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <template x-if="i !== null">
                <div>
                    <div class="mb-1 font-semibold" x-text="tips[i].label"></div>
                    <div class="flex justify-between gap-2"><span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-[#2a78d6] dark:bg-[#3987e5]"></span>{{ __('Sales') }}</span><span class="tabular-nums" x-text="tips[i].sales"></span></div>
                    <div class="flex justify-between gap-2"><span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-[#eb6834] dark:bg-[#d95926]"></span>{{ __('Purchases') }}</span><span class="tabular-nums" x-text="tips[i].purchases"></span></div>
                    <div class="mt-1 flex justify-between gap-2 border-t border-zinc-200 pt-1 dark:border-zinc-700"><span>{{ __('Difference') }}</span><span class="tabular-nums" x-text="tips[i].difference"></span></div>
                </div>
            </template>
        </div>
    </div>

    @if ($data['missing'] > 0)
        <flux:text size="sm" class="text-amber-600 dark:text-amber-400">{{ trans_choice(':count invoice in a foreign currency without an exchange rate is not included.|:count invoices in a foreign currency without an exchange rate are not included.', $data['missing'], ['count' => $data['missing']]) }}</flux:text>
    @endif

    <details class="text-sm">
        <summary class="cursor-pointer text-zinc-500">{{ __('Show as table') }}</summary>
        <flux:table class="mt-2">
            <flux:table.columns>
                <flux:table.column>{{ __('Month') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Sales') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Purchases') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($months as $month)
                    <flux:table.row :key="'month-'.$month['key']">
                        <flux:table.cell>{{ $month['label'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $pln($month['sales']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $pln($month['purchases']) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $pln($month['sales'] - $month['purchases']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </details>
</flux:card>
