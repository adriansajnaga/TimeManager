<?php

use App\Models\Contractor;
use App\Models\MeasurementInstrument;
use App\Models\MeasurementPerformer;
use App\Models\MeasurementProtocol;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?MeasurementProtocol $protocol = null;

    public string $number = '';

    public ?string $contractor_id = null;

    public string $investor = '';

    public string $place = '';

    public string $description = '';

    public string $measured_on = '';

    public ?string $instrument_id = null;

    /** @var list<string> */
    public array $performer_ids = [];

    public string $network = 'TN-C-S';

    public string $phase_voltage = '230';

    public string $line_voltage = '400';

    public string $touch_voltage = '50';

    public string $disconnection_time = '0.4';

    public string $weather = '';

    public string $temperature = '';

    public string $remarks = '';

    public string $verdict = MeasurementProtocol::DEFAULT_VERDICT;

    public string $next_test_on = '';

    /** Wzór niemiecki (klient z DE): Grund der Prüfung i numery zlecenia. */
    public string $inspection_reason = 'new';

    public string $external_order = '';

    public string $internal_order = '';

    public function mount(?MeasurementProtocol $protocol = null): void
    {
        if ($protocol?->exists) {
            $this->protocol = $protocol;
            $this->fill([
                'number' => $protocol->number,
                'contractor_id' => $protocol->contractor_id !== null ? (string) $protocol->contractor_id : null,
                'investor' => (string) $protocol->investor,
                'place' => $protocol->place,
                'description' => (string) $protocol->description,
                'measured_on' => $protocol->measured_on->toDateString(),
                'instrument_id' => $protocol->instrument_id !== null ? (string) $protocol->instrument_id : null,
                'performer_ids' => $protocol->performers()->pluck('measurement_performers.id')->map(fn ($id) => (string) $id)->all(),
                'network' => $protocol->network,
                'phase_voltage' => (string) $protocol->phase_voltage,
                'line_voltage' => (string) $protocol->line_voltage,
                'touch_voltage' => (string) $protocol->touch_voltage,
                'disconnection_time' => (string) (float) $protocol->disconnection_time,
                'weather' => (string) $protocol->weather,
                'temperature' => $protocol->temperature === null ? '' : (string) (float) $protocol->temperature,
                'remarks' => (string) $protocol->remarks,
                'verdict' => (string) $protocol->verdict,
                'next_test_on' => $protocol->next_test_on?->toDateString() ?? '',
                'inspection_reason' => $protocol->inspection_reason,
                'external_order' => (string) $protocol->external_order,
                'internal_order' => (string) $protocol->internal_order,
            ]);

            return;
        }

        // Nowy protokół: dziś, domyślny przyrząd i wszystkie aktywne osoby, termin badań za 5 lat.
        $today = CarbonImmutable::today();
        $this->measured_on = $today->toDateString();
        $this->number = MeasurementProtocol::nextNumber($today)['number'];
        $this->next_test_on = $today->addYears(MeasurementProtocol::NEXT_TEST_YEARS)->toDateString();
        $this->instrument_id = ($id = MeasurementInstrument::query()->where('is_active', true)->orderBy('id')->value('id')) !== null ? (string) $id : null;
        $this->performer_ids = MeasurementPerformer::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Data pomiaru zmienia numer (miesiąc) i termin następnych badań — tylko w nowym protokole. */
    public function updatedMeasuredOn(): void
    {
        if ($this->protocol !== null || ($date = CarbonImmutable::createFromFormat('Y-m-d', $this->measured_on)) === null) {
            return;
        }

        $this->number = MeasurementProtocol::nextNumber($date)['number'];
        $this->next_test_on = $date->addYears(MeasurementProtocol::NEXT_TEST_YEARS)->toDateString();
    }

    /** Kontrahent z kartoteki podpowiada inwestora (nazwa i adres). */
    public function updatedContractorId(): void
    {
        $contractor = $this->contractor_id ? Contractor::find($this->contractor_id) : null;

        if ($contractor !== null && trim($this->investor) === '') {
            $address = collect([$contractor->street, trim($contractor->zip.' '.$contractor->city)])->filter()->implode(', ');
            $this->investor = trim($contractor->name.($address !== '' ? ', '.$address : ''));
        }
    }

    public function save(): void
    {
        $this->authorize('manage-measurements');

        $this->validate([
            'number' => ['required', 'string', 'max:50', Rule::unique('measurement_protocols', 'number')->ignore($this->protocol?->id)],
            'contractor_id' => ['nullable', 'exists:contractors,id'],
            'investor' => ['nullable', 'string', 'max:255'],
            'place' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'measured_on' => ['required', 'date_format:Y-m-d'],
            'instrument_id' => ['nullable', 'exists:measurement_instruments,id'],
            'performer_ids' => ['array'],
            'performer_ids.*' => ['exists:measurement_performers,id'],
            'network' => ['required', Rule::in(MeasurementProtocol::NETWORKS)],
            'phase_voltage' => ['required', 'integer', 'between:12,1000'],
            'line_voltage' => ['required', 'integer', 'between:12,1000'],
            'touch_voltage' => ['required', 'integer', 'between:12,120'],
            'disconnection_time' => ['required', Rule::in(['0.1', '0.2', '0.4', '5'])],
            'weather' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'between:-50,60'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'inspection_reason' => ['required', Rule::in(array_keys(MeasurementProtocol::REASONS))],
            'external_order' => ['nullable', 'string', 'max:100'],
            'internal_order' => ['nullable', 'string', 'max:100'],
            'verdict' => ['nullable', 'string', 'max:5000'],
            'next_test_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $creating = $this->protocol === null;
        $numbering = MeasurementProtocol::nextNumber(CarbonImmutable::parse($this->measured_on));

        $protocol = DB::transaction(function () use ($creating, $numbering) {
            $protocol = $this->protocol ?? new MeasurementProtocol(['created_by' => auth()->id()]);

            // Kolejność w miesiącu nadajemy przy zakładaniu; numer można potem poprawić ręcznie.
            if ($creating) {
                $protocol->fill(['year' => $numbering['year'], 'month' => $numbering['month'], 'sequence' => $numbering['sequence']]);
            }

            $protocol->fill([
                'number' => trim($this->number),
                'contractor_id' => $this->contractor_id ?: null,
                'investor' => trim($this->investor) ?: null,
                'place' => trim($this->place),
                'description' => trim($this->description) ?: null,
                'measured_on' => $this->measured_on,
                'instrument_id' => $this->instrument_id ?: null,
                'network' => $this->network,
                'phase_voltage' => (int) $this->phase_voltage,
                'line_voltage' => (int) $this->line_voltage,
                'touch_voltage' => (int) $this->touch_voltage,
                'disconnection_time' => $this->disconnection_time,
                'weather' => trim($this->weather) ?: null,
                'temperature' => $this->temperature === '' ? null : $this->temperature,
                'remarks' => trim($this->remarks) ?: null,
                'verdict' => trim($this->verdict) ?: null,
                'next_test_on' => $this->next_test_on ?: null,
                'inspection_reason' => $this->inspection_reason,
                'external_order' => trim($this->external_order) ?: null,
                'internal_order' => trim($this->internal_order) ?: null,
            ])->save();

            $protocol->performers()->sync(array_map('intval', $this->performer_ids));

            if ($creating) {
                $protocol->seedInspections();
            }

            return $protocol;
        });

        Flux::toast(variant: 'success', text: __('Protocol saved.'));
        $this->redirectRoute('measurements.show', $protocol, navigate: true);
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function contractors(): Collection
    {
        return Contractor::query()->clients()->orderBy('name')->get(['id', 'name', 'city', 'country_code']);
    }

    /**
     * @return Collection<int, MeasurementInstrument>
     */
    #[Computed]
    public function instruments(): Collection
    {
        return MeasurementInstrument::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->instrument_id))->orderBy('name')->get();
    }

    /**
     * @return Collection<int, MeasurementPerformer>
     */
    #[Computed]
    public function performers(): Collection
    {
        return MeasurementPerformer::query()->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $this->performer_ids))->orderBy('name')->get();
    }

    public function render()
    {
        return $this->view()->title($this->protocol?->number ?? __('New measurement protocol'));
    }
}; ?>

<section class="w-full max-w-4xl">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $protocol ? $protocol->number : __('New measurement protocol') }}</flux:heading>
                <flux:subheading>
                    <flux:link :href="route('measurements.index')" wire:navigate>{{ __('Measurements') }}</flux:link>
                    @if ($protocol)
                        · <flux:link :href="route('measurements.show', $protocol)" wire:navigate>{{ __('Results') }}</flux:link>
                    @endif
                </flux:subheading>
            </div>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>

        <flux:card class="space-y-6">
            <div class="grid gap-6 sm:grid-cols-[1fr_12rem]">
                <flux:input wire:model="place" :label="__('Measurement place')" :placeholder="__('e.g. ul. Mazowiecka 72-86, 87-100 Toruń')" required />
                <flux:input wire:model.live="measured_on" type="date" :label="__('Measurement date')" required />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <x-searchable-select wire:model.live="contractor_id" :label="__('Client (optional)')" :placeholder="__('— none —')" clearable
                    :options="$this->contractors->map(fn ($contractor) => ['value' => (string) $contractor->id, 'label' => $contractor->name, 'search' => (string) $contractor->city])->all()" />
                <flux:input wire:model="number" :label="__('Protocol number')" required />
            </div>

            <flux:input wire:model="investor" :label="__('Investor')" :placeholder="__('Name and address')" />

            {{-- Klient z Niemiec: wydruk na niemieckim wzorze — pola, których nie ma w polskim protokole --}}
            @if ($contractor_id && $this->contractors->firstWhere('id', (int) $contractor_id)?->country_code === 'DE')
                <flux:callout icon="language" color="sky">
                    <flux:callout.text>{{ __('Client from Germany — the protocol is printed on the German form (Prüf- und Messprotokoll) with the floor plan only.') }}</flux:callout.text>
                </flux:callout>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="external_order" :label="__('Externe Auftragsnummer')" />
                    <flux:input wire:model="internal_order" :label="__('Interne Auftragsnummer')" />
                </div>
            @endif
            <flux:input wire:model="description" :label="__('Description')" :placeholder="__('e.g. Electrical installation in a new residential building')" />
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading>{{ __('Instrument and people') }}</flux:heading>

            <flux:select wire:model="instrument_id" :label="__('Measuring instrument')">
                <flux:select.option value="">—</flux:select.option>
                @foreach ($this->instruments as $instrument)
                    <flux:select.option :value="(string) $instrument->id">{{ $instrument->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:field>
                <flux:label>{{ __('Measurements performed by') }}</flux:label>
                <div class="space-y-2">
                    @forelse ($this->performers as $performer)
                        <flux:checkbox wire:model="performer_ids" :value="(string) $performer->id"
                            :label="$performer->name" :description="implode(' · ', $performer->certificateLines())" />
                    @empty
                        <flux:text size="sm">{{ __('Add people with their qualification certificates under Measurements → Instruments and people.') }}</flux:text>
                    @endforelse
                </div>
            </flux:field>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading>{{ __('Network parameters') }}</flux:heading>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                <flux:select wire:model="network" :label="__('Network')">
                    @foreach (MeasurementProtocol::NETWORKS as $network)
                        <flux:select.option :value="$network">{{ $network }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="phase_voltage" :label="__('Uo [V]')" inputmode="numeric" />
                <flux:input wire:model="line_voltage" :label="__('U [V]')" inputmode="numeric" />
                <flux:input wire:model="touch_voltage" :label="__('UL [V]')" inputmode="numeric" />
                <flux:select wire:model="disconnection_time" :label="__('ta [s]')">
                    @foreach (['0.1', '0.2', '0.4', '5'] as $time)
                        <flux:select.option :value="$time">{{ str_replace('.', ',', $time) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="weather" :label="__('Weather (earthing)')" :placeholder="__('e.g. sunny')" />
                <flux:input wire:model="temperature" :label="__('Outside temperature [°C]')" inputmode="decimal" />
            </div>
        </flux:card>

        <flux:card class="space-y-6">
            <flux:heading>{{ __('Conclusion') }}</flux:heading>
            <flux:textarea wire:model="remarks" :label="__('Remarks')" rows="4" />
            <flux:textarea wire:model="verdict" :label="__('Verdict')" rows="3" />
            <flux:input wire:model="next_test_on" type="date" :label="__('Next test due')" class="max-w-56" />
        </flux:card>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
