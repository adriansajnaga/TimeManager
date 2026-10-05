<?php

use App\Models\Note;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Notes')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Najnowiej zmienione na górze; szukanie w tytule, treści i nazwach plików.
     *
     * @return LengthAwarePaginator<int, Note>
     */
    #[Computed]
    public function notes(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Note::query()
            ->withCount('attachments')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%')
                    ->orWhereHas('attachments', fn ($files) => $files->where('name', 'like', '%'.$search.'%'));
            }))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Notes') }}</flux:heading>
            <flux:subheading>{{ __('Your notes with attached files.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('notes.create')" wire:navigate>
            {{ __('New note') }}
        </flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search in title, text and file names')" class="max-w-sm" />

    <flux:table :paginate="$this->notes">
        <flux:table.columns>
            <flux:table.column>{{ __('Note') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Files') }}</flux:table.column>
            <flux:table.column>{{ __('Changed') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->notes as $note)
                <flux:table.row :key="$note->id">
                    <flux:table.cell class="max-w-0 w-full">
                        <flux:link :href="route('notes.edit', $note)" wire:navigate class="font-medium">{{ $note->title }}</flux:link>
                        @if (filled($note->body))
                            <div class="truncate text-sm text-zinc-500">{{ Str::limit(preg_replace('/\s+/', ' ', (string) $note->body), 160) }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="center">
                        @if ($note->attachments_count > 0)
                            <flux:badge size="sm" icon="paper-clip">{{ $note->attachments_count }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $note->updated_at->format('d.m.Y H:i') }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="text-center">{{ __('No notes yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
