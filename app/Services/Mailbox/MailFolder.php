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
     * Folder wysłanych po nazwie (Sent, Sent Items, Wysłane, Gesendet…), także jako INBOX.Sent.
     */
    public static function looksLikeSent(string $path): bool
    {
        return preg_match('/(^|[.\/])(sent( items| messages| mail)?|wys(ł|l)ane|elementy wys(ł|l)ane|gesendet|gesendete (elemente|objekte))$/iu', $path) === 1;
    }

    /**
     * Kosz rozpoznajemy po nazwie (Trash, Deleted, Kosz, Papierkorb).
     */
    public static function looksLikeTrash(string $path): bool
    {
        return preg_match('/(^|[.\/])(trash|deleted( items| messages)?|kosz|papierkorb)$/i', $path) === 1;
    }
}
