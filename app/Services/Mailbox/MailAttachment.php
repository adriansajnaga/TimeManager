<?php

namespace App\Services\Mailbox;

final class MailAttachment
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public readonly ?string $content = null,
    ) {}

    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' MB'
            : max(1, (int) round($this->size / 1024)).' kB';
    }
}
