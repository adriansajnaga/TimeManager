<?php

namespace App\Services\Mailbox;

final class MailFolder
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
    ) {}
}
