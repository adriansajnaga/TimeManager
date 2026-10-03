<?php

namespace App\Services\Mailbox;

use Carbon\CarbonImmutable;

/**
 * Wiadomość na liście (nagłówki).
 */
final class MailSummary
{
    public function __construct(
        public readonly int $uid,
        public readonly string $from,
        public readonly string $subject,
        public readonly ?CarbonImmutable $date,
        public readonly bool $seen,
        public readonly bool $hasAttachments,
    ) {}
}
