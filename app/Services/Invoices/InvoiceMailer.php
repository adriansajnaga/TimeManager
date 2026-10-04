<?php

namespace App\Services\Invoices;

use App\Documents\DocumentFormat;
use App\Documents\InvoicePdf;
use App\Documents\PdfRenderer;
use App\Documents\SettlementPackage;
use App\Enums\Language;
use App\Mail\InvoiceMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\Mailbox\Mailbox;
use App\Support\DefaultTemplates;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wysyłka faktury e-mailem — zawsze ręcznie, po podglądzie (decyzja 6).
 * Załącznik: pakiet rozliczenia (gdy faktura z rozliczenia) albo sama faktura.
 */
final class InvoiceMailer
{
    public const MAILER = 'tm_smtp';

    /** Błąd zapisu kopii w Wysłanych przy ostatniej wysyłce (wysyłka sama się udała). */
    public ?string $sentCopyError = null;

    public function __construct(
        private readonly SettlementPackage $package,
        private readonly PdfRenderer $renderer,
    ) {}

    /**
     * Treść do podglądu: adresy i szablony z kartoteki klienta.
     *
     * @return array{to: string, cc: string, subject: string, body: string, attachment: string}
     */
    public function draft(Invoice $invoice, User $user): array
    {
        $contractor = $invoice->contractor;
        $language = $contractor !== null ? $contractor->document_language : Language::Polish;
        $format = new DocumentFormat($language);
        $sender = MailSetting::current()->from_name ?: $user->name;

        $replace = [
            '{number}' => (string) $invoice->number,
            '{date}' => $format->date($invoice->issue_date),
            '{sender}' => $sender,
        ];

        $to = $contractor !== null ? ($contractor->email_to ?? array_filter([$contractor->email])) : [];

        return [
            'to' => implode(', ', $to),
            'cc' => implode(', ', $contractor->email_cc ?? []),
            'subject' => strtr($contractor?->email_subject_template ?: DefaultTemplates::emailSubject($language), $replace),
            'body' => strtr($contractor?->email_body_template ?: DefaultTemplates::emailBody($language), $replace),
            'attachment' => $this->filename($invoice),
        ];
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     *
     * @throws InvoiceException
     */
    public function send(Invoice $invoice, User $user, array $to, array $cc, string $subject, string $body): EmailLog
    {
        $settings = MailSetting::current();

        if (! $settings->isConfigured()) {
            throw new InvoiceException(__('Configure the e-mail server in Administration → E-mail first.'));
        }

        if (! $invoice->isSales() || $invoice->isDraft()) {
            throw new InvoiceException(__('Only issued sales invoices can be sent.'));
        }

        $log = new EmailLog([
            'invoice_id' => $invoice->id,
            'to' => $to,
            'cc' => $cc ?: null,
            'subject' => $subject,
            'body' => $body,
            'attachment' => $this->filename($invoice),
            'sent_by' => $user->id,
        ]);

        try {
            $pdf = $this->renderer->render($this->package->documents($invoice), $invoice->displayNumber());

            self::useSettings($settings);

            $sent = Mail::mailer(self::MAILER)
                ->to($to)
                ->cc($cc)
                ->bcc(array_filter([$settings->bcc]))
                ->send(new InvoiceMail($subject, $body, $pdf, (string) $log->attachment, (string) $settings->from_address, $settings->from_name));
        } catch (Throwable $exception) {
            report($exception);
            $log->forceFill(['error' => $exception->getMessage()])->save();

            throw new InvoiceException(__('The e-mail was not sent: :message', ['message' => $exception->getMessage()]));
        }

        $log->forceFill(['sent_at' => now()])->save();
        $invoice->forceFill(['emailed_at' => now()])->save();

        $this->sentCopyError = self::saveToSent($sent ?? null, $settings);

        return $log;
    }

    /**
     * Kopia wysłanej wiadomości w folderze Wysłane skrzynki (IMAP). Błąd zapisu nie cofa wysyłki
     * — zwracamy go, żeby pokazać użytkownikowi.
     */
    public static function saveToSent(?SentMessage $sent, MailSetting $settings): ?string
    {
        if ($sent === null || ! $settings->hasMailbox()) {
            return null;
        }

        try {
            app(Mailbox::class)->appendToSent($sent->toString());
        } catch (Throwable $exception) {
            report($exception);

            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Rejestruje mailer SMTP z ustawień aplikacji (dane z bazy, nie z .env).
     */
    public static function useSettings(MailSetting $settings): void
    {
        config(['mail.mailers.'.self::MAILER => $settings->mailerConfig()]);
        Mail::purge(self::MAILER);
    }

    private function filename(Invoice $invoice): string
    {
        return $invoice->settlement !== null
            ? $this->package->filename($invoice)
            : (new InvoicePdf($invoice))->filename();
    }
}
