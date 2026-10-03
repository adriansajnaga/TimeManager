<?php

namespace App\Services\LegacyImport;

/**
 * Teksty ze starej bazy (kolumny latin2). Stara aplikacja zapisywała UTF-8 przez połączenie latin2,
 * więc większość tekstów jest podwójnie zakodowana („LadegerĂ¤t” zamiast „Ladegerät”), a część
 * (wpisana np. przez phpMyAdmin) jest poprawna. Naprawiamy tylko te, które po cofnięciu
 * konwersji dają poprawny UTF-8.
 */
final class LegacyText
{
    public static function repair(?string $value): ?string
    {
        if ($value === null || ! preg_match('/[^\x00-\x7F]/', $value)) {
            return $value;
        }

        $bytes = mb_convert_encoding($value, 'ISO-8859-2', 'UTF-8');

        if ($bytes !== $value && mb_check_encoding($bytes, 'UTF-8') && preg_match('/[^\x00-\x7F]/', $bytes)) {
            return $bytes;
        }

        return $value;
    }

    /**
     * Naprawiony, przycięty tekst albo null dla pustego.
     */
    public static function clean(mixed $value): ?string
    {
        $text = trim((string) self::repair($value === null ? null : (string) $value));

        // Znak „Ø” przepadł w starej bazie jako „?” (np. „Kernbohrungen ?160 mm”).
        $text = (string) preg_replace('/\?(?=\d+(?:[.,]\d+)?\s*mm\b)/u', 'Ø', $text);

        return $text === '' ? null : $text;
    }

    /**
     * Kod kraju z nazwy („Deutschland” → DE, „Polska” → PL).
     */
    public static function countryCode(?string $country): string
    {
        return match (mb_strtolower(trim((string) $country))) {
            'polska', 'poland', 'polen', 'pl' => 'PL',
            'österreich', 'austria', 'at' => 'AT',
            default => 'DE',
        };
    }

    /**
     * Numer podatkowy z opisu: „USt-Id-Nr.: DE 286771111” → ['DE', '286771111'], „NIP: 8792451081” → [null, '8792451081'].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function taxId(?string $value): array
    {
        $text = (string) $value;

        if (preg_match('/\b([A-Z]{2})\s*(\d[\d\s]{6,})/', $text, $match)) {
            return [$match[1], preg_replace('/\s+/', '', $match[2])];
        }

        if (preg_match('/(\d[\d\s-]{6,})/', $text, $match)) {
            return [null, preg_replace('/[\s-]+/', '', $match[1])];
        }

        return [null, null];
    }

    /**
     * Kod pocztowy: „D-24143” → „24143”, liczba 4113 (utracone zero z przodu) → „04113”.
     */
    public static function zip(mixed $value, string $countryCode): ?string
    {
        $zip = trim((string) $value);
        $zip = (string) preg_replace('/^[A-Z]{1,2}-/', '', $zip);

        if ($zip === '' || $zip === '0') {
            return null;
        }

        return $countryCode === 'DE' && ctype_digit($zip) ? str_pad($zip, 5, '0', STR_PAD_LEFT) : $zip;
    }
}
