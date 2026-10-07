<?php

use App\Models\Contracts\Attachable;
use App\Livewire\ComponentWithAttachments;
use App\Models\Note;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;

new class extends ComponentWithAttachments {
    public ?Note $note = null;

    public string $title = '';

    public string $body = '';

    public function mount(?Note $note = null): void
    {
        if ($note?->exists) {
            $this->note = $note;
            $this->title = $note->title;
            $this->body = (string) $note->body;
        }
    }

    public function save(): void
    {
        $this->authorize('manage-notes');

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:1000000'],
            ...$this->uploadRules(),
        ]);

        $creating = $this->note === null;
        $note = $this->note ?? new Note(['user_id' => auth()->id()]);
        $note->fill(['title' => trim($this->title), 'body' => $this->body !== '' ? $this->body : null]);
        $note->save();

        if ($this->uploads !== []) {
            $note->touch();
        }

        $this->storeUploads($note);

        Flux::toast(variant: 'success', text: __('Note saved.'));

        if ($creating) {
            $this->redirectRoute('notes.edit', $note, navigate: true);

            return;
        }

        $this->note = $note->refresh();
    }

    /** Zapis automatyczny: chwilę po wpisaniu tytułu lub treści (nowa notatka — gdy ma tytuł). */
    public function updatedTitle(): void
    {
        $this->autosave();
    }

    public function updatedBody(): void
    {
        $this->autosave();
    }

    private function autosave(): void
    {
        if (trim($this->title) === '') {
            return;
        }

        $this->authorize('manage-notes');
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:1000000'],
        ]);

        $creating = $this->note === null;
        $note = $this->note ?? new Note(['user_id' => auth()->id()]);
        $note->fill(['title' => trim($this->title), 'body' => $this->body !== '' ? $this->body : null])->save();
        $this->note = $note->refresh();

        if ($creating) {
            // Pliki wybrane przed nadaniem tytułu dołączamy od razu; adres strony bez przeładowania.
            $this->storeUploads($note);
            $this->js('window.history.replaceState({}, "", '.json_encode(route('notes.edit', $note)).')');
        }
    }

    public function delete(): void
    {
        $this->authorize('manage-notes');

        $this->note?->delete();

        Flux::toast(variant: 'success', text: __('Note deleted.'));
        $this->redirectRoute('notes.index', navigate: true);
    }

    protected function attachmentOwner(): (Model&Attachable)|null
    {
        return $this->note;
    }

    protected function attachmentPermission(): string
    {
        return 'manage-notes';
    }
}; ?>

<section class="w-full max-w-5xl">
    <form wire:submit="save" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $note?->title ?? __('New note') }}</flux:heading>
                <flux:subheading>
                    <flux:link :href="route('notes.index')" wire:navigate>{{ __('Notes') }}</flux:link>
                    @if ($note)
                        · {{ __('Changed') }} {{ $note->updated_at->format('d.m.Y H:i') }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($note)
                    <flux:button variant="danger" icon="trash" wire:click="delete"
                        wire:confirm="{{ __('Delete this note together with its files?') }}">{{ __('Delete') }}</flux:button>
                @endif
            </div>
        </div>

        <flux:card class="space-y-6">
            <flux:input wire:model.live.debounce.800ms="title" :label="__('Title')" required autofocus />
            <flux:textarea wire:model.live.debounce.1500ms="body" :label="__('Text')" rows="14" resize="vertical" />
            <flux:text size="sm" class="text-zinc-500">
                <span wire:loading wire:target="title, body">{{ __('Saving…') }}</span>
                <span wire:loading.remove wire:target="title, body">{{ $note ? __('Saved automatically.') : __('The note is saved automatically once it has a title.') }}</span>
            </flux:text>
        </flux:card>

        <x-attachments :owner="$note" :uploads="$uploads" />
    </form>
</section>
