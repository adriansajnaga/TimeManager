<?php

namespace App\Services\Mailbox;

final class MailFolder
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly ?int $unseen = null,
        public readonly bool $isTrash = false,
    ) {}

    /**
     * Kosz rozpoznajemy po nazwie (Trash, Deleted, Kosz, Papierkorb).
     */
    public static function looksLikeTrash(string $path): bool
    {
        return preg_match('/(^|[.\/])(trash|deleted( items| messages)?|kosz|papierkorb)$/i', $path) === 1;
    }
}
