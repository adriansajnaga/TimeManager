@props([
    'options' => [],
    'label' => null,
    'placeholder' => null,
    'searchPlaceholder' => null,
    'name' => null,
    'clearable' => false,
])

{{--
    Lista wyboru z polem szukania (Flux w wersji darmowej go nie ma).
    $options: list<array{value: int|string, label: string, search?: string}>; wire:model jak przy flux:select.
    Szukanie ignoruje wielkość liter i znaki diakrytyczne (np. „lurssen” znajdzie „Lürssen”).
    clearable: na górze listy pozycja z placeholderem — wybór „brak” (wartość pusta).
--}}
@php
    $model = $attributes->wire('model')->value();
    $name ??= $model;
    $placeholder ??= __('Choose…');
    $options = $clearable ? [['value' => '', 'label' => $placeholder, 'empty' => true], ...array_values($options)] : array_values($options);
@endphp

<flux:field>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    <div
        x-data="{
            value: null,
            open: false,
            query: '',
            active: 0,
            options: @js($options),
            normalize(text) {
                return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
            },
            get filtered() {
                const words = this.normalize(this.query).split(/\s+/).filter(Boolean);
                if (words.length === 0) return this.options;
                return this.options.filter(option => {
                    const haystack = this.normalize(option.label + ' ' + (option.search ?? ''));
                    return words.every(word => haystack.includes(word));
                });
            },
            get selectedLabel() {
                const option = this.options.find(option => String(option.value) === String(this.value ?? ''));
                return option && ! option.empty ? option.label : '';
            },
            show() {
                this.open = true;
                this.query = '';
                this.active = Math.max(0, this.filtered.findIndex(option => String(option.value) === String(this.value)));
                this.$nextTick(() => { this.$refs.search.focus(); this.scrollToActive(); });
            },
            hide() {
                this.open = false;
            },
            choose(option) {
                this.value = option.value;
                this.hide();
                this.$refs.button.focus();
            },
            move(step) {
                const count = this.filtered.length;
                if (count === 0) return;
                this.active = (this.active + step + count) % count;
                this.$nextTick(() => this.scrollToActive());
            },
            scrollToActive() {
                this.$refs.list?.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' });
            },
            confirm() {
                const option = this.filtered[this.active];
                if (option) this.choose(option);
            },
        }"
        x-modelable="value"
        wire:key="searchable-{{ md5((string) json_encode($options)) }}"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:click.outside="hide()"
        x-on:keydown.escape.stop.prevent="hide(); $refs.button.focus()"
        class="relative"
    >
        <button type="button" x-ref="button" x-on:click="open ? hide() : show()"
            x-on:keydown.down.prevent="show()"
            class="flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-start text-base text-zinc-700 shadow-xs sm:text-sm dark:border-white/10 dark:bg-white/10 dark:text-zinc-300">
            <span class="truncate" x-text="selectedLabel || @js($placeholder)" x-bind:class="selectedLabel ? '' : 'text-zinc-400 dark:text-zinc-400'"></span>
            <flux:icon.chevron-up-down variant="mini" class="shrink-0 text-zinc-400" />
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
            class="absolute inset-x-0 z-50 mt-1 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-600 dark:bg-zinc-700">
            <div class="border-b border-zinc-200 p-2 dark:border-zinc-600">
                <input type="text" x-ref="search" x-model="query" x-on:input="active = 0"
                    x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="confirm()" x-on:keydown.tab="hide()"
                    placeholder="{{ $searchPlaceholder ?? __('Search…') }}" autocomplete="off"
                    class="w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm text-zinc-800 outline-none focus:border-zinc-400 dark:border-zinc-500 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <ul x-ref="list" class="max-h-64 overflow-y-auto py-1" role="listbox">
                <template x-for="(option, index) in filtered" :key="option.value">
                    <li role="option" x-on:click="choose(option)" x-on:mousemove="active = index"
                        x-bind:data-active="index === active"
                        x-bind:aria-selected="String(option.value) === String(value)"
                        x-bind:class="index === active ? 'bg-zinc-100 dark:bg-zinc-600' : ''"
                        class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-zinc-800 dark:text-zinc-100">
                        <flux:icon.check variant="mini" class="shrink-0 text-zinc-500" x-bind:class="String(option.value) === String(value) ? '' : 'invisible'" />
                        <span class="truncate" x-text="option.label"></span>
                    </li>
                </template>
                <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-zinc-500">{{ __('Nothing found.') }}</li>
            </ul>
        </div>
    </div>

    @if ($name)
        <flux:error :name="$name" />
    @endif
</flux:field>
