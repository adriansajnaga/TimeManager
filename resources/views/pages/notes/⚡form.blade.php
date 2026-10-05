<?php

use App\Models\Note;
use App\Models\NoteAttachment;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ?Note $note = null;

    public string $title = '';

    public string $body = '';

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

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

    /**
     * W istniejącej notatce pliki dołączamy od razu po wybraniu.
     */
    public function updatedUploads(): void
    {
        if ($this->note === null) {
            return;
        }

        $this->authorize('manage-notes');
        $this->validate($this->uploadRules());

        $this->storeUploads($this->note);
        $this->note->touch();

        Flux::toast(variant: 'success', text: __('Files attached.'));
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function deleteAttachment(int $id): void
    {
        $this->authorize('manage-notes');

        $this->note?->attachments()->whereKey($id)->first()?->delete();
        $this->note?->touch();

        Flux::toast(variant: 'success', text: __('File deleted.'));
    }

    public function delete(): void
    {
        $this->authorize('manage-notes');

        $this->note?->delete();

        Flux::toast(variant: 'success', text: __('Note deleted.'));
        $this->redirectRoute('notes.index', navigate: true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function uploadRules(): array
    {
        return [
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['file', 'max:'.NoteAttachment::MAX_KB],
        ];
    }

    private function storeUploads(Note $note): void
    {
        foreach ($this->uploads as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $stored = $file->storeAs(
                NoteAttachment::DIRECTORY.'/'.$note->id,
                Str::uuid()->toString().($extension !== '' ? '.'.$extension : ''),
                'local',
            );

            $note->attachments()->create([
                'name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'path' => (string) $stored,
                'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                'size' => (int) $file->getSize(),
            ]);
        }

        $this->uploads = [];
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

        <flux:card class="space-y-4">
            <div>
                <flux:heading>{{ __('Files') }}</flux:heading>
                <flux:text size="sm">{{ __('Any file type, up to :size MB each.', ['size' => NoteAttachment::MAX_KB / 1024]) }}</flux:text>
            </div>

            @if ($note && $note->attachments->isNotEmpty())
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($note->attachments as $attachment)
                        @php([$icon, $color] = $attachment->icon())
                        <div wire:key="attachment-{{ $attachment->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                            <flux:icon :name="$icon" class="size-8 shrink-0 {{ $color }}" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm" title="{{ $attachment->name }}">{{ $attachment->name }}</div>
                                <div class="text-xs text-zinc-500">{{ strtoupper(pathinfo($attachment->name, PATHINFO_EXTENSION) ?: $attachment->kind()) }} · {{ $attachment->sizeLabel() }}</div>
                            </div>
                            @if ($attachment->previewable())
                                <flux:button size="xs" variant="ghost" icon="eye" target="_blank" :aria-label="__('Open')"
                                    :href="route('notes.attachment', ['attachment' => $attachment, 'inline' => 1])" />
                            @endif
                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                                :href="route('notes.attachment', $attachment)" />
                            <flux:button size="xs" variant="ghost" icon="trash" :aria-label="__('Delete')"
                                wire:click="deleteAttachment({{ $attachment->id }})"
                                wire:confirm="{{ __('Delete the file :name?', ['name' => $attachment->name]) }}" />
                        </div>
                    @endforeach
                </div>
            @endif

            <flux:input type="file" wire:model="uploads" multiple :label="$note ? __('Attach files') : __('Files to attach on save')" />

            <div wire:loading wire:target="uploads" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>

            @if ($uploads !== [])
                <ul class="space-y-1 text-sm">
                    @foreach ($uploads as $index => $upload)
                        <li wire:key="upload-{{ $index }}" class="flex items-center gap-2">
                            <flux:icon.paper-clip variant="micro" class="text-zinc-400" />
                            <span class="truncate">{{ $upload->getClientOriginalName() }}</span>
                            <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="removeUpload({{ $index }})" :aria-label="__('Remove')" />
                        </li>
                    @endforeach
                </ul>
            @endif

            <flux:error name="uploads" />
            <flux:error name="uploads.*" />
        </flux:card>
    </form>
</section>
