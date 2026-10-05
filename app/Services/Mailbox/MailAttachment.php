<?php

namespace App\Services\Mailbox;

use App\Support\DescribesFile;

final class MailAttachment
{
    use DescribesFile;

    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public readonly ?string $content = null,
    ) {}
}
