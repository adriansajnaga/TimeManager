@props([
    'owner' => null,
    'uploads' => [],
    'heading' => null,
    'pendingLabel' => null,
])

{{-- Kafelki plików rekordu (podpis, ważność, podgląd, pobranie, usunięcie) i pole wgrywania; akcje z ComponentWithAttachments. --}}
@php($attachments = $owner?->attachments ?? collect())

<flux:card class="space-y-4">
    <div>
        <flux:heading>{{ $heading ?? __('Files') }}</flux:heading>
        <flux:text size="sm">{{ __('Any file type, up to :size MB each. Use the pencil to add a caption or an expiry date.', ['size' => \App\Models\Attachment::MAX_KB / 1024]) }}</flux:text>
    </div>

    @if ($attachments->isNotEmpty())
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($attachments as $attachment)
                @php([$icon, $color] = $attachment->icon())
                @php($state = $attachment->expiryState())
                <div wire:key="attachment-{{ $attachment->id }}" @class([
                    'flex items-center gap-3 rounded-lg border p-2',
                    'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10' => $state === 'expired',
                    'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => $state === 'soon',
                    'border-zinc-200 dark:border-zinc-700' => ! in_array($state, ['expired', 'soon'], true),
                ])>
                    <flux:icon :name="$icon" class="size-8 shrink-0 {{ $color }}" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium" title="{{ $attachment->label() }}">{{ $attachment->label() }}</div>
                        @if (filled($attachment->description))
                            <div class="truncate text-xs text-zinc-500" title="{{ $attachment->name }}">{{ $attachment->name }}</div>
                        @endif
                        <div class="text-xs text-zinc-500">{{ strtoupper(pathinfo($attachment->name, PATHINFO_EXTENSION) ?: $attachment->kind()) }} · {{ $attachment->sizeLabel() }} · {{ $attachment->created_at?->format('d.m.Y') }}</div>
                        @if ($state !== null)
                            <flux:badge size="sm" class="mt-1" :color="match ($state) { 'expired' => 'red', 'soon' => 'amber', default => 'green' }" icon="calendar">
                                @if ($state === 'expired')
                                    {{ __('Expired :date', ['date' => $attachment->expires_at->format('d.m.Y')]) }}
                                @else
                                    {{ __('Valid until :date', ['date' => $attachment->expires_at->format('d.m.Y')]) }}
                                @endif
                            </flux:badge>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col gap-0.5 sm:flex-row">
                        @if ($attachment->previewable())
                            <flux:button size="xs" variant="ghost" icon="eye" target="_blank" :aria-label="__('Open')"
                                :href="route('attachments.show', ['attachment' => $attachment, 'inline' => 1])" />
                        @endif
                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                            :href="route('attachments.show', $attachment)" />
                        <flux:button size="xs" variant="ghost" icon="pencil-square" :aria-label="__('Caption and expiry')"
                            wire:click="editAttachment({{ $attachment->id }})" />
                        <flux:button size="xs" variant="ghost" icon="trash" :aria-label="__('Delete')"
                            wire:click="deleteAttachment({{ $attachment->id }})"
                            wire:confirm="{{ __('Delete the file :name?', ['name' => $attachment->label()]) }}" />
                    </div>
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

<flux:modal name="attachment-edit" class="md:w-[28rem]">
    <form wire:submit="saveAttachment" class="space-y-5">
        <flux:heading size="lg">{{ __('Caption and expiry') }}</flux:heading>

        <flux:input wire:model="attachmentDescription" :label="__('Caption')" :placeholder="__('e.g. Framework agreement 2026, A1 certificate')" />

        <flux:checkbox wire:model="attachmentHasExpiry" :label="__('The document has an expiry date')" />

        <div x-show="$wire.attachmentHasExpiry" x-cloak>
            <flux:input wire:model="attachmentExpiresAt" type="date" :label="__('Valid until')"
                :description="__('The dashboard shows an alert :days days before.', ['days' => \App\Models\Attachment::WARN_DAYS])" />
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
