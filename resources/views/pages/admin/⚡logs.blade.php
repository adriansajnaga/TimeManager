<?php

use App\Support\ErrorLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Error log')] class extends Component {
    /**
     * @return list<array{time: string, level: string, message: string, trace: string}>
     */
    #[Computed]
    public function entries(): array
    {
        return ErrorLog::latest();
    }

    public function refresh(): void
    {
        unset($this->entries);
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Error log') }}</flux:heading>
            <flux:subheading>{{ __('The latest application errors, newest first (from storage/logs).') }}</flux:subheading>
        </div>
        <flux:button icon="arrow-path" wire:click="refresh">{{ __('Refresh') }}</flux:button>
    </div>

    @forelse ($this->entries as $index => $entry)
        <flux:card class="space-y-2" wire:key="log-{{ $index }}">
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" color="red">{{ $entry['level'] }}</flux:badge>
                <span class="text-sm text-zinc-500">{{ $entry['time'] }}</span>
            </div>
            <div class="break-words font-mono text-sm">{{ $entry['message'] }}</div>
            @if ($entry['trace'] !== '')
                <details>
                    <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Details') }}</summary>
                    <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-zinc-100 p-2 text-xs dark:bg-white/5">{{ $entry['trace'] }}</pre>
                </details>
            @endif
        </flux:card>
    @empty
        <flux:callout icon="check-circle" color="green">
            <flux:callout.text>{{ __('No errors in the log.') }}</flux:callout.text>
        </flux:callout>
    @endforelse
</section>
