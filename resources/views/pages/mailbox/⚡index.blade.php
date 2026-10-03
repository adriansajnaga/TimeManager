<?php

use App\Services\Mailbox\MailFolder;
use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use App\Services\Mailbox\MailMessage;
use App\Services\Mailbox\MailSummary;
use App\Services\Mailbox\MailView;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Mailbox')] class extends Component {
    private const PER_PAGE = 30;

    #[Url(except: 'INBOX')]
    public string $folder = 'INBOX';

    #[Url(except: 1)]
    public int $page = 1;

    #[Url(except: '')]
    public string $search = '';

    /** Otwarta wiadomość (UID w folderze). */
    #[Url(except: 0)]
    public int $uid = 0;

    public bool $remoteImages = false;

    public ?string $error = null;

    public ?string $messageError = null;

    public function updatedFolder(): void
    {
        $this->page = 1;
        $this->search = '';
        $this->uid = 0;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
        $this->uid = 0;
    }

    public function openFolder(string $path): void
    {
        $this->folder = $path;
        $this->page = 1;
        $this->search = '';
        $this->uid = 0;
    }

    public function open(int $uid): void
    {
        $this->uid = $uid;
        $this->remoteImages = false;
        unset($this->messages, $this->folders);
    }

    public function close(): void
    {
        $this->uid = 0;
    }

    public function goTo(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function refresh(): void
    {
        unset($this->messages, $this->folders);
    }

    public function showImages(): void
    {
        $this->remoteImages = true;
    }

    public function toggleSeen(int $uid, bool $seen): void
    {
        $this->authorize('use-mailbox');

        try {
            app(Mailbox::class)->setSeen($this->folder, $uid, $seen);
        } catch (MailboxException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        if (! $seen && $this->uid === $uid) {
            $this->uid = 0;
        }

        unset($this->messages, $this->folders);
    }

    public function delete(int $uid): void
    {
        $this->authorize('use-mailbox');

        try {
            $toTrash = app(Mailbox::class)->delete($this->folder, $uid);
        } catch (MailboxException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        if ($this->uid === $uid) {
            $this->uid = 0;
        }

        unset($this->messages, $this->folders);
        Flux::toast(text: $toTrash ? __('Moved to Trash.') : __('Message deleted.'));
    }

    /**
     * @return list<MailFolder>
     */
    #[Computed]
    public function folders(): array
    {
        try {
            return app(Mailbox::class)->folders();
        } catch (MailboxException $exception) {
            $this->error = $exception->getMessage();

            return [];
        }
    }

    public function currentFolder(): ?MailFolder
    {
        foreach ($this->folders as $item) {
            if ($item->path === $this->folder) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{messages: list<MailSummary>, total: int}
     */
    #[Computed]
    public function messages(): array
    {
        if ($this->error !== null) {
            return ['messages' => [], 'total' => 0];
        }

        try {
            return app(Mailbox::class)->messages($this->folder, $this->page, self::PER_PAGE, trim($this->search));
        } catch (MailboxException $exception) {
            $this->error = $exception->getMessage();

            return ['messages' => [], 'total' => 0];
        }
    }

    #[Computed]
    public function message(): ?MailMessage
    {
        if ($this->uid === 0) {
            return null;
        }

        try {
            return app(Mailbox::class)->message($this->folder, $this->uid);
        } catch (MailboxException $exception) {
            $this->messageError = $exception->getMessage();

            return null;
        }
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->messages['total'] / self::PER_PAGE));
    }
}; ?>

@php
    // Najpierw wiadomość (oznacza ją jako przeczytaną), potem lista i foldery z licznikami.
    $message = $this->message;
    $folders = $this->folders;
    $result = $this->messages;
    $inTrash = $this->currentFolder()?->isTrash ?? false;
@endphp

<section class="flex h-[calc(100vh-4rem)] w-full flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('Mailbox') }}</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search in subject and sender')" class="max-w-xs" />
            <flux:button icon="arrow-path" wire:click="refresh" :aria-label="__('Refresh')" />
        </div>
    </div>

    @if ($this->error)
        <flux:callout icon="exclamation-triangle" color="red" :heading="__('Cannot open the mailbox')">
            <flux:callout.text>{{ $this->error }}</flux:callout.text>
            @can('manage-settings')
                <flux:callout.text><flux:link :href="route('admin.mail')" wire:navigate>{{ __('E-mail settings') }}</flux:link></flux:callout.text>
            @endcan
        </flux:callout>
    @else
        {{-- Szerokie okno: foldery | lista | wiadomość; węższe: lista | wiadomość, foldery jako lista rozwijana. --}}
        <div class="grid min-h-0 flex-1 gap-4 md:grid-cols-[minmax(16rem,22rem)_1fr] 2xl:grid-cols-[11rem_22rem_1fr]">
            {{-- Foldery --}}
            <nav class="hidden space-y-1 overflow-y-auto 2xl:block">
                @foreach ($folders as $item)
                    <button type="button" wire:click="openFolder(@js($item->path))"
                        @class(['flex h-9 w-full items-center justify-between gap-2 rounded-lg px-3 text-start text-sm', 'bg-zinc-800/5 font-medium text-zinc-800 dark:bg-white/10 dark:text-white' => $item->path === $folder, 'text-zinc-500 hover:bg-zinc-800/5 hover:text-zinc-800 dark:text-white/80 dark:hover:bg-white/[7%] dark:hover:text-white' => $item->path !== $folder])>
                        <span class="truncate">{{ $item->name }}</span>
                        @if ($item->unseen)
                            <flux:badge size="sm" color="blue">{{ $item->unseen }}</flux:badge>
                        @endif
                    </button>
                @endforeach
            </nav>

            {{-- Lista wiadomości --}}
            <div @class(['flex min-h-0 flex-col gap-2', 'hidden md:flex' => $uid !== 0])>
                <div class="2xl:hidden">
                    <flux:select wire:model.live="folder" size="sm">
                        @foreach ($folders as $item)
                            <flux:select.option :value="$item->path">{{ $item->name }}{{ $item->unseen ? ' ('.$item->unseen.')' : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            <div class="flex min-h-0 flex-1 flex-col rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="min-h-0 flex-1 divide-y divide-zinc-200 overflow-y-auto dark:divide-zinc-700">
                    @forelse ($result['messages'] as $item)
                        <div wire:key="msg-{{ $item->uid }}" wire:click="open({{ $item->uid }})"
                            @class(['flex cursor-pointer items-center gap-2 px-3 py-2', 'bg-zinc-800/10 dark:bg-white/10' => $item->uid === $uid, 'hover:bg-zinc-800/5 dark:hover:bg-white/[7%]' => $item->uid !== $uid])>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span @class(['size-2 shrink-0 rounded-full', 'bg-blue-500' => ! $item->seen, 'bg-transparent' => $item->seen]) title="{{ $item->seen ? __('Read') : __('Unread') }}"></span>
                                    <span @class(['flex-1 truncate text-sm', 'font-semibold text-zinc-900 dark:text-white' => ! $item->seen, 'text-zinc-600 dark:text-zinc-300' => $item->seen])>{{ $item->from }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ $item->date?->isToday() ? $item->date->format('H:i') : $item->date?->format('d.m.y') }}</span>
                                </div>
                                <div class="flex items-center gap-2 ps-4">
                                    <span @class(['flex-1 truncate text-sm', 'font-semibold text-zinc-900 dark:text-white' => ! $item->seen, 'text-zinc-500 dark:text-zinc-400' => $item->seen])>{{ $item->subject !== '' ? $item->subject : __('(no subject)') }}</span>
                                    @if ($item->hasAttachments)
                                        <flux:icon.paper-clip variant="micro" class="shrink-0 text-zinc-400" />
                                    @endif
                                </div>
                            </div>
                            {{-- Akcje zawsze widoczne: przeczytana/nieprzeczytana i usuń. --}}
                            <div class="flex shrink-0 flex-col" wire:click.stop>
                                <flux:button size="xs" variant="subtle" :icon="$item->seen ? 'envelope' : 'envelope-open'"
                                    wire:click="toggleSeen({{ $item->uid }}, {{ $item->seen ? 'false' : 'true' }})"
                                    :tooltip="$item->seen ? __('Mark as unread') : __('Mark as read')" />
                                <flux:button size="xs" variant="subtle" icon="trash"
                                    wire:click="delete({{ $item->uid }})"
                                    :wire:confirm="$inTrash ? __('Delete this message permanently?') : null"
                                    :tooltip="__('Delete')" />
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-center text-sm text-zinc-500">{{ __('No messages.') }}</div>
                    @endforelse
                </div>

                @if ($this->lastPage() > 1)
                    <div class="flex items-center justify-between border-t border-zinc-200 p-2 dark:border-zinc-700">
                        <flux:button size="xs" variant="ghost" icon="chevron-left" wire:click="goTo({{ $page - 1 }})" :disabled="$page <= 1">{{ __('Newer') }}</flux:button>
                        <flux:text size="sm">{{ __('Page :page of :pages', ['page' => $page, 'pages' => $this->lastPage()]) }}</flux:text>
                        <flux:button size="xs" variant="ghost" icon:trailing="chevron-right" wire:click="goTo({{ $page + 1 }})" :disabled="$page >= $this->lastPage()">{{ __('Older') }}</flux:button>
                    </div>
                @endif
            </div>
            </div>

            {{-- Wiadomość (na telefonie tylko po otwarciu) --}}
            <div @class(['min-h-0 flex-col rounded-lg border border-zinc-200 dark:border-zinc-700', 'flex' => $uid !== 0, 'hidden md:flex' => $uid === 0])>
                @if ($message)
                    <div class="space-y-3 border-b border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <flux:heading size="lg">{{ $message->subject !== '' ? $message->subject : __('(no subject)') }}</flux:heading>
                            <div class="flex gap-1">
                                <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="close" class="md:hidden" :aria-label="__('Back')" />
                                <flux:button size="sm" variant="ghost" icon="envelope" wire:click="toggleSeen({{ $message->uid }}, false)">{{ __('Mark as unread') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $message->uid }})"
                                    :wire:confirm="$inTrash ? __('Delete this message permanently?') : null">{{ __('Delete') }}</flux:button>
                            </div>
                        </div>
                        <div class="text-sm">
                            <div><span class="text-zinc-500">{{ __('From') }}:</span> <strong>{{ $message->from }}</strong></div>
                            <div><span class="text-zinc-500">{{ __('To') }}:</span> {{ implode(', ', $message->to) }}</div>
                            @if ($message->cc !== [])
                                <div><span class="text-zinc-500">{{ __('Copy (CC)') }}:</span> {{ implode(', ', $message->cc) }}</div>
                            @endif
                            <div class="text-zinc-500">{{ $message->date?->translatedFormat('l, d.m.Y H:i') }}</div>
                        </div>

                        @if ($message->attachments !== [])
                            <div class="flex flex-wrap gap-2">
                                @foreach ($message->attachments as $attachment)
                                    @php([$icon, $color] = $attachment->icon())
                                    <div class="flex w-56 items-center gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                                        <flux:icon :name="$icon" class="size-8 shrink-0 {{ $color }}" />
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm" title="{{ $attachment->name }}">{{ $attachment->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ strtoupper(pathinfo($attachment->name, PATHINFO_EXTENSION) ?: $attachment->kind()) }} · {{ $attachment->sizeLabel() }}</div>
                                        </div>
                                        @if ($attachment->previewable())
                                            <flux:button size="xs" variant="ghost" icon="eye" target="_blank" :aria-label="__('Open')"
                                                :href="route('mailbox.attachment', ['folder' => $folder, 'uid' => $message->uid, 'index' => $attachment->index, 'inline' => 1])" />
                                        @endif
                                        <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :aria-label="__('Download')"
                                            :href="route('mailbox.attachment', ['folder' => $folder, 'uid' => $message->uid, 'index' => $attachment->index])" />
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if (! $remoteImages && MailView::hasRemoteImages($message))
                            <flux:text size="sm">
                                {{ __('Images from the internet are blocked (they can reveal that you opened the message).') }}
                                <flux:link wire:click="showImages" class="cursor-pointer">{{ __('Show images') }}</flux:link>
                            </flux:text>
                        @endif
                    </div>

                    {{-- Ramka bez skryptów i formularzy; linki otwierają się w nowej karcie. --}}
                    <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"
                        srcdoc="{{ MailView::document($message, $remoteImages) }}"
                        class="min-h-[50vh] w-full flex-1 rounded-b-lg bg-white"
                        title="{{ __('Message') }}"></iframe>
                @elseif ($messageError)
                    <div class="p-6"><flux:text class="text-red-600">{{ $messageError }}</flux:text></div>
                @else
                    <div class="flex flex-1 items-center justify-center p-6 text-sm text-zinc-500">
                        <div class="text-center">
                            <flux:icon.envelope class="mx-auto mb-2 size-10 text-zinc-300" />
                            {{ __('Select a message to read it.') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</section>
