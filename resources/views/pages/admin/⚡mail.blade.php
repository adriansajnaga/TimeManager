<?php

use App\Models\MailSetting;
use App\Services\Invoices\InvoiceMailer;
use Flux\Flux;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('E-mail')] class extends Component {
    public string $host = '';

    public int|string $port = 465;

    public string $encryption = 'ssl';

    public string $username = '';

    /** Nowe hasło; puste = zostaw zapisane. */
    public string $password = '';

    public string $from_address = '';

    public string $from_name = '';

    public string $bcc = '';

    public bool $hasPassword = false;

    public ?string $verifiedAt = null;

    public function mount(): void
    {
        $this->fillFrom(MailSetting::current());
    }

    public function save(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailSetting::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'bcc' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = MailSetting::query()->first() ?? new MailSetting;
        $settings->fill([
            'host' => trim($this->host),
            'port' => (int) $this->port,
            'encryption' => $this->encryption,
            'username' => trim($this->username) ?: null,
            'from_address' => trim($this->from_address),
            'from_name' => trim($this->from_name) ?: null,
            'bcc' => trim($this->bcc) ?: null,
            'verified_at' => null,
        ]);

        if ($this->password !== '') {
            $settings->password = $this->password;
        }

        $settings->save();
        $this->reset('password');
        $this->fillFrom($settings);

        Flux::toast(variant: 'success', text: __('E-mail settings saved.'));
    }

    public function test(): void
    {
        $this->authorize('manage-settings');

        $settings = MailSetting::current();
        $recipient = (string) auth()->user()->email;

        try {
            InvoiceMailer::useSettings($settings);

            Mail::mailer(InvoiceMailer::MAILER)->raw(
                __('Test message from :app. The e-mail server settings work.', ['app' => config('app.name')]),
                fn (Message $message) => $message
                    ->to($recipient)
                    ->from((string) $settings->from_address, $settings->from_name)
                    ->subject(__('Test message from :app', ['app' => config('app.name')])),
            );
        } catch (Throwable $exception) {
            $this->addError('test', $exception->getMessage());

            return;
        }

        $settings->forceFill(['verified_at' => now()])->save();
        $this->fillFrom($settings);

        Flux::toast(variant: 'success', text: __('Test message sent to :email.', ['email' => $recipient]));
    }

    private function fillFrom(MailSetting $settings): void
    {
        $this->host = (string) $settings->host;
        $this->port = $settings->port;
        $this->encryption = $settings->encryption;
        $this->username = (string) $settings->username;
        $this->from_address = (string) $settings->from_address;
        $this->from_name = (string) $settings->from_name;
        $this->bcc = (string) $settings->bcc;
        $this->hasPassword = filled($settings->password);
        $this->verifiedAt = $settings->verified_at?->format('d.m.Y H:i');
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('E-mail') }}</flux:heading>
        <flux:subheading>{{ __('SMTP server for sending invoices. E-mails are never sent automatically — always after a preview.') }}</flux:subheading>
    </div>

    <flux:card class="space-y-6">
        @if ($verifiedAt)
            <flux:badge color="green" icon="check-circle">{{ __('Last successful test: :date', ['date' => $verifiedAt]) }}</flux:badge>
        @endif

        <form wire:submit="save" class="space-y-6">
            <div class="grid gap-6 sm:grid-cols-[1fr_7rem_8rem]">
                <flux:input wire:model="host" :label="__('SMTP server')" placeholder="mail.example.com" />
                <flux:input wire:model="port" type="number" :label="__('Port')" />
                <flux:select wire:model="encryption" :label="__('Encryption')">
                    @foreach (MailSetting::ENCRYPTIONS as $value)
                        <flux:select.option :value="$value">{{ strtoupper($value) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="username" :label="__('User name')" autocomplete="off" />
                <flux:input wire:model="password" type="password" viewable autocomplete="new-password" :label="__('Password')"
                    :placeholder="$hasPassword ? __('Leave empty to keep the saved password') : ''"
                    :description="__('Stored encrypted with the application key (APP_KEY).')" />
                <flux:input wire:model="from_address" type="email" :label="__('Sender address')" />
                <flux:input wire:model="from_name" :label="__('Sender name')" />
                <flux:input wire:model="bcc" type="email" :label="__('Hidden copy (BCC) of every e-mail')" />
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <flux:button icon="paper-airplane" wire:click="test" wire:loading.attr="disabled">{{ __('Send a test message to me') }}</flux:button>
            </div>

            <flux:error name="test" />
        </form>
    </flux:card>
</section>
