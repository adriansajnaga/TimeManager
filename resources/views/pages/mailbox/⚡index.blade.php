<?php

use App\Services\Mailbox\MailFolder;
use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use App\Services\Mailbox\MailSummary;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Mailbox')] class extends Component {
    private const PER_PAGE = 25;

    #[Url(except: 'INBOX')]
    public string $folder = 'INBOX';

    #[Url(except: 1)]
    public int $page = 1;

    #[Url(except: '')]
    public string $search = '';

    public ?string $error = null;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function openFolder(string $path): void
    {
        $this->folder = $path;
        $this->page = 1;
        $this->search = '';
    }

    public function goTo(int $page): void
    {
        $this->page = max(1, $page);
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

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->messages['total'] / self::PER_PAGE));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Mailbox') }}</flux:heading>
            <flux:subheading>{{ __('Company mailbox (read only). Messages stay on the mail server.') }}</flux:subheading>
        </div>

        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Search in subject and sender')" class="max-w-xs" />
    </div>

    @php($folders = $this->folders)
    @php($result = $this->messages)

    @if ($this->error)
        <flux:callout icon="exclamation-triangle" color="red" :heading="__('Cannot open the mailbox')">
            <flux:callout.text>{{ $this->error }}</flux:callout.text>
            @can('manage-settings')
                <flux:callout.text><flux:link :href="route('admin.mail')" wire:navigate>{{ __('E-mail settings') }}</flux:link></flux:callout.text>
            @endcan
        </flux:callout>
    @else
        <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
            <nav class="space-y-1">
                @foreach ($folders as $item)
                    <button type="button" wire:click="openFolder(@js($item->path))"
                        @class(['block w-full truncate rounded px-3 py-1.5 text-start text-sm', 'bg-zinc-200 font-semibold dark:bg-zinc-700' => $item->path === $folder, 'hover:bg-zinc-100 dark:hover:bg-zinc-800' => $item->path !== $folder])>
                        {{ $item->name }}
                    </button>
                @endforeach
            </nav>

            <div class="space-y-3">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('From') }}</flux:table.column>
                        <flux:table.column>{{ __('Subject') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Date') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($result['messages'] as $message)
                            <flux:table.row :key="'msg-'.$message->uid">
                                <flux:table.cell @class(['font-semibold' => ! $message->seen])>
                                    <span class="block max-w-56 truncate">{{ $message->from }}</span>
                                </flux:table.cell>
                                <flux:table.cell @class(['font-semibold' => ! $message->seen])>
                                    <flux:link :href="route('mailbox.show', ['folder' => $folder, 'uid' => $message->uid])" wire:navigate>
                                        {{ $message->subject !== '' ? $message->subject : __('(no subject)') }}
                                    </flux:link>
                                    @if ($message->hasAttachments)
                                        <flux:icon.paper-clip variant="micro" class="inline text-zinc-400" />
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">{{ $message->date?->format('d.m.Y H:i') }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center">{{ __('No messages.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                @if ($this->lastPage() > 1)
                    <div class="flex items-center justify-between">
                        <flux:button size="sm" icon="chevron-left" wire:click="goTo({{ $page - 1 }})" :disabled="$page <= 1">{{ __('Newer') }}</flux:button>
                        <flux:text size="sm">{{ __('Page :page of :pages', ['page' => $page, 'pages' => $this->lastPage()]) }}</flux:text>
                        <flux:button size="sm" icon:trailing="chevron-right" wire:click="goTo({{ $page + 1 }})" :disabled="$page >= $this->lastPage()">{{ __('Older') }}</flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</section>
