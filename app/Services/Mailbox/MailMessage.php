<?php

namespace App\Services\Mailbox;

use Carbon\CarbonImmutable;

/**
 * Pełna wiadomość: nagłówki, treść (HTML i/lub tekst) i lista załączników (bez treści).
 */
final class MailMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<MailAttachment>  $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $html,
        public readonly ?string $text,
        public readonly array $attachments,
    ) {}
}
