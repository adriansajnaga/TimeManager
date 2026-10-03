<?php

use App\Models\Contractor;
use App\Services\LegacyImport\LegacyImporter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Import from the old application')] class extends Component {
    public string $client_id = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->client_id = (string) Contractor::query()->where('name', 'like', 'Gärtner%')->value('id');
    }

    /**
     * Liczba rekordów w starej bazie albo komunikat błędu połączenia.
     *
     * @return array<string, int>|string
     */
    #[Computed]
    public function source(): array|string
    {
        if (! LegacyImporter::isConfigured()) {
            return __('The old database is not configured.');
        }

        try {
            return LegacyImporter::connection()->sourceCounts();
        } catch (Throwable $exception) {
            return __('Cannot connect to the old database: :message', ['message' => $exception->getMessage()]);
        }
    }

    /**
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Contractor::query()->clients()->orderBy('name')->get(['id', 'name']);
    }

    public function run(bool $dryRun): void
    {
        $this->authorize('manage-settings');
        $this->validate(['client_id' => ['required', 'integer', 'exists:contractors,id']]);

        set_time_limit(300);

        $report = LegacyImporter::connection()->run(Contractor::query()->findOrFail($this->client_id), $dryRun);

        $this->result = [
            'dry_run' => $report->dryRun,
            'counts' => $report->counts,
            'legacy_hours' => $report->legacyHours,
            'imported_hours' => $report->importedHours,
            'weeks' => count($report->weeks),
            'mismatches' => $report->weekMismatches(),
            'warnings' => $report->warnings,
        ];
    }
}; ?>

<section class="w-full max-w-4xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Import from the old application') }}</flux:heading>
        <flux:subheading>{{ __('Projects, working time and closed weeks from the old TM database. Safe to run again: existing data is never overwritten.') }}</flux:subheading>
    </div>

    @if (is_string($this->source))
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ $this->source }}</flux:callout.text>
            <flux:callout.text>{{ __('Set LEGACY_DB_DATABASE, LEGACY_DB_USERNAME and LEGACY_DB_PASSWORD in the .env file.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:card class="space-y-5">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->source as $table => $count)
                    <flux:badge size="sm">{{ $table }}: {{ $count }}</flux:badge>
                @endforeach
            </div>

            <flux:select wire:model="client_id" :label="__('Client for all imported projects')" class="max-w-md">
                <flux:select.option value="">{{ __('Choose a client') }}</flux:select.option>
                @foreach ($this->clients as $client)
                    <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex flex-wrap gap-2">
                <flux:button icon="magnifying-glass" wire:click="run(true)" wire:loading.attr="disabled">{{ __('Check without saving') }}</flux:button>
                <flux:button variant="primary" icon="arrow-down-tray" wire:click="run(false)" wire:loading.attr="disabled"
                    wire:confirm="{{ __('Import the data now?') }}" data-test="run-import-button">
                    {{ __('Import') }}
                </flux:button>
                <flux:text wire:loading wire:target="run">{{ __('Working…') }}</flux:text>
            </div>
        </flux:card>
    @endif

    @if ($result)
        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="lg">{{ $result['dry_run'] ? __('Check result (nothing saved)') : __('Import result') }}</flux:heading>
                <flux:badge :color="$result['mismatches'] === [] && $result['legacy_hours'] === $result['imported_hours'] ? 'green' : 'amber'">
                    {{ __('Hours') }}: {{ $result['legacy_hours'] }} → {{ $result['imported_hours'] }}
                </flux:badge>
                <flux:text>{{ __(':count weeks compared', ['count' => $result['weeks']]) }}</flux:text>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Section') }}</flux:table.column>
                    <flux:table.column>{{ __('Item') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($result['counts'] as $section => $counts)
                        @foreach ($counts as $key => $value)
                            <flux:table.row :key="$section.$key">
                                <flux:table.cell>{{ $section }}</flux:table.cell>
                                <flux:table.cell>{{ $key }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $value }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($result['mismatches'] !== [])
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('Weeks with different hours') }}</flux:callout.heading>
                    @foreach ($result['mismatches'] as $mismatch)
                        <flux:callout.text>{{ $mismatch['week'] }}: {{ $mismatch['legacy'] }} → {{ $mismatch['imported'] }}</flux:callout.text>
                    @endforeach
                </flux:callout>
            @endif

            @if ($result['warnings'] !== [])
                <div class="space-y-1">
                    <flux:heading size="sm">{{ __('Notes') }} ({{ count($result['warnings']) }})</flux:heading>
                    <ul class="list-disc space-y-0.5 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($result['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </flux:card>
    @endif
</section>
