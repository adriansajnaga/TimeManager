@props([
    'owner' => null,
    'uploads' => [],
    'heading' => null,
    'pendingLabel' => null,
])

{{-- Kafelki plików rekordu (podgląd, pobranie, usunięcie) i pole wgrywania; akcje z traitu ManagesAttachments. --}}
@php($attachments = $owner?->attachments ?? collect())

<flux:card class="space-y-4">
    <div>
        <flux:heading>{{ $heading ?? __('Files') }}</flux:heading>
        <flux:text size="sm">{{ __('Any file type, up to :size MB each.', ['size' => \App\Models\Attachment::MAX_KB / 1024]) }}</flux:text>
    </div>

    @if ($attachments->isNotEmpty())
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($attachments as $attachment)
                @php([$icon, $color] = $attachment->icon())
                <div wire:key="attachment-{{ $attachment->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                    <flux:icon :name="$icon" class="size-8 shrink-0 {{ $color }}" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm" title="{{ $attachment->name }}">{{ $attachment->name }}</div>
                        <div class="text-xs text-zinc-500">{{ strtoupper(pathinfo($attachment->name, PATHINFO_EXTENSION) ?: $attachment->kind()) }} · {{ $attachment->sizeLabel() }} · {{ $attachment->created_at?->format('d.m.Y') }}</div>
                    </div>
                    @if ($attachment->previewable())
                        <flux:button size="xs" variant="ghost" icon="eye" target="_blank" :aria-label="__('Open')"
                            :href="route('attachments.show', ['attachment' => $attachment, 'inline' => 1])" />
                    @endif
                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                        :href="route('attachments.show', $attachment)" />
                    <flux:button size="xs" variant="ghost" icon="trash" :aria-label="__('Delete')"
                        wire:click="deleteAttachment({{ $attachment->id }})"
                        wire:confirm="{{ __('Delete the file :name?', ['name' => $attachment->name]) }}" />
                </div>
            @endforeach
        </div>
    @endif

    <flux:input type="file" wire:model="uploads" multiple :label="$owner ? __('Attach files') : ($pendingLabel ?? __('Files to attach on save'))" />

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
