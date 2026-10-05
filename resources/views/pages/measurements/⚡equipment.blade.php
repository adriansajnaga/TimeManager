<?php

use App\Models\MeasurementInstrument;
use App\Models\MeasurementPerformer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Instruments and people')] class extends Component {
    /**
     * @return Collection<int, MeasurementInstrument>
     */
    #[Computed]
    public function instruments(): Collection
    {
        return MeasurementInstrument::query()->withCount('attachments')->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, MeasurementPerformer>
     */
    #[Computed]
    public function performers(): Collection
    {
        return MeasurementPerformer::query()->withCount('attachments')->orderByDesc('is_active')->orderBy('name')->get();
    }
}; ?>

<section class="w-full max-w-4xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Instruments and people') }}</flux:heading>
        <flux:subheading>
            <flux:link :href="route('measurements.index')" wire:navigate>{{ __('Measurements') }}</flux:link>
            · {{ __('Calibration certificates and qualification certificates are attached to every report.') }}
        </flux:subheading>
    </div>

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Measuring instruments') }}</flux:heading>
            <flux:button size="sm" icon="plus" :href="route('measurements.instrument', ['instrument' => 'new'])" wire:navigate>{{ __('Instrument') }}</flux:button>
        </div>
        @forelse ($this->instruments as $instrument)
            <a href="{{ route('measurements.instrument', $instrument) }}" wire:navigate wire:key="instrument-{{ $instrument->id }}"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3 hover:border-zinc-400 dark:border-zinc-700">
                <div>
                    <div class="font-medium">{{ $instrument->name }} @unless ($instrument->is_active)<flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>@endunless</div>
                    <div class="text-sm text-zinc-500">{{ __('Serial number') }}: {{ $instrument->serial_number ?: '—' }} · {{ trans_choice(':count file|:count files', $instrument->attachments_count, ['count' => $instrument->attachments_count]) }}</div>
                </div>
                @if ($instrument->calibration_valid_until)
                    <flux:badge size="sm" :color="$instrument->calibration_valid_until->isPast() ? 'red' : ($instrument->calibration_valid_until->lte(now()->addDays(30)) ? 'amber' : 'green')">
                        {{ __('Calibration valid until :date', ['date' => $instrument->calibration_valid_until->format('d.m.Y')]) }}
                    </flux:badge>
                @endif
            </a>
        @empty
            <flux:text>{{ __('No instruments yet.') }}</flux:text>
        @endforelse
    </flux:card>

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('People performing measurements') }}</flux:heading>
            <flux:button size="sm" icon="plus" :href="route('measurements.performer', ['performer' => 'new'])" wire:navigate>{{ __('Person') }}</flux:button>
        </div>
        @forelse ($this->performers as $performer)
            <a href="{{ route('measurements.performer', $performer) }}" wire:navigate wire:key="performer-{{ $performer->id }}"
                class="block rounded-lg border border-zinc-200 p-3 hover:border-zinc-400 dark:border-zinc-700">
                <div class="font-medium">{{ $performer->name }} @unless ($performer->is_active)<flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>@endunless</div>
                <div class="text-sm text-zinc-500">{{ implode(' · ', $performer->certificateLines()) ?: '—' }} · {{ trans_choice(':count file|:count files', $performer->attachments_count, ['count' => $performer->attachments_count]) }}</div>
            </a>
        @empty
            <flux:text>{{ __('No people yet.') }}</flux:text>
        @endforelse
    </flux:card>
</section>
