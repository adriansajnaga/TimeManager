<?php

use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use App\Services\Mailbox\MailMessage;
use App\Services\Mailbox\MailView;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public string $folder = 'INBOX';

    #[Url]
    public int $uid = 0;

    public bool $remoteImages = false;

    public ?string $error = null;

    public function showImages(): void
    {
        $this->remoteImages = true;
    }

    public function message(): ?MailMessage
    {
        try {
            return app(Mailbox::class)->message($this->folder, $this->uid);
        } catch (MailboxException $exception) {
            $this->error = $exception->getMessage();

            return null;
        }
    }

    public function render()
    {
        return $this->view()->title(__('Message'));
    }
}; ?>

@php($message = $this->message())

<section class="w-full max-w-5xl space-y-4">
    <flux:link :href="route('mailbox.index', ['folder' => $folder])" wire:navigate>← {{ __('Mailbox') }}</flux:link>

    @if ($message === null)
        <flux:callout icon="exclamation-triangle" color="red" :heading="__('Cannot open the message')">
            <flux:callout.text>{{ $this->error }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $message->subject !== '' ? $message->subject : __('(no subject)') }}</flux:heading>
            <flux:text>{{ __('From') }}: <strong>{{ $message->from }}</strong></flux:text>
            <flux:text>{{ __('To') }}: {{ implode(', ', $message->to) }}</flux:text>
            @if ($message->cc !== [])
                <flux:text>{{ __('Copy (CC)') }}: {{ implode(', ', $message->cc) }}</flux:text>
            @endif
            <flux:text size="sm">{{ $message->date?->format('d.m.Y H:i') }}</flux:text>
        </div>

        @if ($message->attachments !== [])
            <div class="flex flex-wrap gap-2">
                @foreach ($message->attachments as $attachment)
                    <flux:button size="sm" icon="paper-clip" :href="route('mailbox.attachment', ['folder' => $folder, 'uid' => $message->uid, 'index' => $attachment->index])">
                        {{ $attachment->name }} ({{ $attachment->sizeLabel() }})
                    </flux:button>
                @endforeach
            </div>
        @endif

        @if (! $remoteImages && MailView::hasRemoteImages($message))
            <flux:callout icon="photo" color="zinc">
                <flux:callout.text>
                    {{ __('Images from the internet are blocked (they can reveal that you opened the message).') }}
                    <flux:link wire:click="showImages" class="cursor-pointer">{{ __('Show images') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif

        {{-- Ramka bez skryptów i formularzy; linki otwierają się w nowej karcie. --}}
        <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"
            srcdoc="{{ MailView::document($message, $remoteImages) }}"
            class="h-[70vh] w-full rounded-lg border border-zinc-200 bg-white dark:border-zinc-700"
            title="{{ __('Message') }}"></iframe>
    @endif
</section>
