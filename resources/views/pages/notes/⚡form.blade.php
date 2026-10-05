<?php

use App\Livewire\ComponentWithAttachments;
use App\Models\Contractor;
use App\Models\Note;
use Flux\Flux;

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

    public function delete(): void
    {
        $this->authorize('manage-notes');

        $this->note?->delete();

        Flux::toast(variant: 'success', text: __('Note deleted.'));
        $this->redirectRoute('notes.index', navigate: true);
    }

    protected function attachmentOwner(): Note|Contractor|null
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
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </div>

        <flux:card class="space-y-6">
            <flux:input wire:model="title" :label="__('Title')" required autofocus />
            <flux:textarea wire:model="body" :label="__('Text')" rows="14" resize="vertical" />
        </flux:card>

        <x-attachments :owner="$note" :uploads="$uploads" />
    </form>
</section>
