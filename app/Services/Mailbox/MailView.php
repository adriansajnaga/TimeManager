<?php

namespace App\Services\Mailbox;

/**
 * Treść wiadomości do wyświetlenia w ramce z atrybutem sandbox (bez skryptów i formularzy).
 * Polityka CSP blokuje zdalne zasoby — obrazy z internetu dopiero na życzenie (piksele śledzące).
 */
final class MailView
{
    public static function document(MailMessage $message, bool $remoteImages = false): string
    {
        $images = $remoteImages ? 'data: cid: https: http:' : 'data: cid:';
        $policy = "default-src 'none'; style-src 'unsafe-inline'; img-src {$images}; font-src data:";

        $body = $message->html !== null && trim($message->html) !== ''
            ? $message->html
            : '<pre style="white-space: pre-wrap; font-family: inherit;">'.e((string) $message->text).'</pre>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="'.$policy.'">'
            .'<base target="_blank">'
            .'<style>body{font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;background:#fff;margin:12px;}img{max-width:100%;height:auto;}</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    /**
     * Czy treść HTML odwołuje się do obrazów z internetu (pokazujemy wtedy przycisk).
     */
    public static function hasRemoteImages(MailMessage $message): bool
    {
        return $message->html !== null && preg_match('/<img[^>]+src=["\']?https?:/i', $message->html) === 1;
    }
}
