<?php

namespace App\Services\Mailbox;

use Throwable;

/**
 * Dekodowanie nagłówków e-mail (RFC 2047: „=?UTF-8?Q?Fwd=3A_Sajanga_pe=C5=82n?=”).
 * Sąsiednie zakodowane fragmenty łączymy przed konwersją znaków — znak wielobajtowy
 * bywa rozdzielony między dwa fragmenty (np. „ł” = C5 | 82).
 */
final class MailHeader
{
    /**
     * Wartość pola z surowego nagłówka (z rozwiniętymi liniami kontynuacji), np. „Subject”.
     */
    public static function field(string $rawHeader, string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':[ \t]*(.*(?:\r?\n[ \t].*)*)/mi', $rawHeader, $matches) !== 1) {
            return null;
        }

        return self::decode($matches[1]);
    }

    public static function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Rozwinięcie zawiniętych linii i spacje między zakodowanymi fragmentami (nie należą do treści).
        $value = preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/(\?=)[ \t]+(?==\?)/', '$1', $value) ?? $value;

        $pattern = '/=\?([^?*]+)(?:\*[^?]*)?\?([QqBb])\?([^?]*)\?=/';

        if (preg_match($pattern, $value) !== 1) {
            return trim(self::toUtf8($value, 'UTF-8'));
        }

        $result = '';
        $offset = 0;
        $pendingBytes = '';
        $pendingCharset = null;

        preg_match_all($pattern, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $charset = strtoupper($match[1][0]);

            // Zwykły tekst między fragmentami kończy grupę.
            $plain = substr($value, $offset, $start - $offset);

            if ($plain !== '' || ($pendingCharset !== null && $pendingCharset !== $charset)) {
                $result .= self::flush($pendingBytes, $pendingCharset).$plain;
                $pendingBytes = '';
            }

            $pendingCharset = $charset;
            $pendingBytes .= strtoupper($match[2][0]) === 'B'
                ? (string) base64_decode($match[3][0], false)
                : quoted_printable_decode(str_replace('_', ' ', $match[3][0]));

            $offset = $start + strlen($whole);
        }

        $result .= self::flush($pendingBytes, $pendingCharset).substr($value, $offset);

        return trim($result);
    }

    private static function flush(string $bytes, ?string $charset): string
    {
        return $bytes === '' ? '' : self::toUtf8($bytes, $charset ?? 'UTF-8');
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper($charset);

        if (($charset === 'UTF-8' || $charset === 'US-ASCII') && mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        try {
            $converted = mb_convert_encoding($text, 'UTF-8', $charset === 'US-ASCII' ? 'UTF-8' : $charset);
        } catch (Throwable) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        }

        return $converted !== false ? $converted : (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }
}
