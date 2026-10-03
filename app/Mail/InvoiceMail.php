<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Faktura (albo cały pakiet) w załączniku, treść z szablonu klienta.
 */
class InvoiceMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        private readonly string $pdf,
        private readonly string $filename,
        private readonly string $fromAddress,
        private readonly ?string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invoice',
            text: 'mail.invoice-text',
            with: ['body' => $this->body],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $pdf = $this->pdf;

        return [Attachment::fromData(fn () => $pdf, $this->filename)->withMime('application/pdf')];
    }
}
