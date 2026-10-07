<?php

namespace App\Services\Measurements;

/**
 * Szyna rozdzielnicy jako SVG w milimetrach (do PDF): mPDF nie trzyma szerokości komórek tabel,
 * a SVG ma dokładną skalę — 1:1 w legendzie, puste szyny zachowują wysokość.
 * Proporcje jak w edytorze: opis nad modułem, moduł z numerem, dźwigienką i zabezpieczeniem.
 *
 * @phpstan-type Resolved array{t: string, label: string, sub: string, desc: string, w: int}
 */
final class BoardSvg
{
    /** Wysokość modułu (front aparatu) względem jego szerokości — 18 mm × 45 mm. */
    public const BOX_RATIO = 2.5;

    /** Wysokość pola opisu nad modułem względem szerokości modułu. */
    public const DESC_RATIO = 1.8;

    /**
     * @param  list<Resolved>  $items
     * @param  float  $module  szerokość modułu w mm
     */
    public static function rail(array $items, int $rail, float $module): string
    {
        $used = array_sum(array_column($items, 'w'));
        $slots = max($rail, $used);
        $width = $slots * $module;
        $desc = round($module * self::DESC_RATIO, 2);
        $box = round($module * self::BOX_RATIO, 2);
        $height = $desc + $box + 0.6;
        $font = max(1.9, min(2.6, $module * 0.15));

        $parts = [];
        $x = 0.0;

        foreach ($items as $item) {
            $w = $item['w'] * $module;
            $parts[] = self::description($item['desc'], $x, $w, $desc, $font, $item['w'] < 2);
            $parts[] = self::module($item, $x + 0.15, $desc, $w - 0.3, $box, $module, $font);
            $x += $w;
        }

        for ($i = $used; $i < $rail; $i++) {
            // Wolny moduł — zaślepka.
            $parts[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="'.self::n(0.6).'" fill="#fafafa" stroke="#d4d4d8" stroke-width="'.self::n(0.25).'" />', self::n($x + 0.15), self::n($desc), self::n($module - 0.3), self::n($box));
            $x += $module;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$smm" height="%2$smm" viewBox="0 0 %3$s %4$s" font-family="dejavusanscondensed">%5$s</svg>',
            self::mm($width), self::mm($height), self::n($width), self::n($height), implode('', $parts),
        );
    }

    /**
     * @param  Resolved  $item
     */
    private static function module(array $item, float $x, float $y, float $w, float $h, float $module, float $font): string
    {
        if ($item['t'] === 'gap') {
            return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="'.self::n(0.6).'" fill="none" stroke="#a1a1aa" stroke-width="'.self::n(0.25).'" stroke-dasharray="'.self::n(1).','.self::n(0.8).'" />', self::n($x), self::n($y), self::n($w), self::n($h));
        }

        $fill = $item['t'] === 'circuit' ? '#ffffff' : '#d4d4d8';
        $cx = $x + $w / 2;
        $lever = [$module * 0.28, $h * 0.3];

        return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="'.self::n(0.6).'" fill="%s" stroke="#52525b" stroke-width="'.self::n(0.3).'" />', self::n($x), self::n($y), self::n($w), self::n($h), $fill)
            .self::text($item['label'], $cx, $y + $h * 0.08 + $font * 1.15, $font * 1.15, 'bold')
            .sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="'.self::n(0.4).'" fill="#3f3f46" />', self::n($cx - $lever[0] / 2), self::n($y + ($h - $lever[1]) / 2), self::n($lever[0]), self::n($lever[1]))
            .self::text($item['sub'], $cx, $y + $h * 0.92, $font * 0.85);
    }

    /**
     * Opis nad modułem: wąski — pionowo (od dołu do góry), szeroki (RCD, F0, WG) — poziomo; oba zawijane.
     */
    private static function description(string $text, float $x, float $w, float $h, float $font, bool $vertical): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $length = $vertical ? $h - 1.5 : $w - 1.5;
        $across = $vertical ? $w - 1 : $h - 1.5;
        $lineHeight = $font * 1.2;
        $lines = self::wrap($text, $length, $font, max(1, (int) floor($across / $lineHeight)));

        $out = '';

        foreach ($lines as $i => $line) {
            if ($vertical) {
                // Kolumny od lewej; tekst czytany od dołu, jak na naklejkach w rozdzielnicy.
                $count = count($lines);
                $tx = $x + $w / 2 + ($i - ($count - 1) / 2) * $lineHeight + $font * 0.35;
                $ty = $h - 1;
                $out .= sprintf('<g transform="rotate(-90 %1$s %2$s)">%3$s</g>', self::n($tx), self::n($ty), self::text($line, $tx, $ty, $font, 'normal', 'start'));
            } else {
                $count = count($lines);
                $ty = $h - 1.2 - ($count - 1 - $i) * $lineHeight;
                $out .= self::text($line, $x + $w / 2, $ty, $font);
            }
        }

        return $out;
    }

    /**
     * Zawija tekst na linie o długości $length mm (przybliżona szerokość znaku), najwyżej $max linii.
     *
     * @return list<string>
     */
    private static function wrap(string $text, float $length, float $font, int $max): array
    {
        $perLine = max(3, (int) floor($length / ($font * 0.52)));
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            while (mb_strlen($word) > $perLine) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $perLine);
                $word = mb_substr($word, $perLine);
            }

            if ($line === '') {
                $line = $word;
            } elseif (mb_strlen($line.' '.$word) <= $perLine) {
                $line .= ' '.$word;
            } else {
                $lines[] = $line;
                $line = $word;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $last = (string) array_pop($lines);
            $lines[] = mb_substr($last, 0, max(1, $perLine - 1)).'…';
        }

        return $lines;
    }

    private static function text(string $text, float $x, float $y, float $size, string $weight = 'normal', string $anchor = 'middle'): string
    {
        if ($text === '') {
            return '';
        }

        return sprintf(
            '<text x="%s" y="%s" font-size="%s" font-weight="%s" text-anchor="%s" fill="#18181b">%s</text>',
            self::n($x), self::n($y), self::n($size), $weight, $anchor, htmlspecialchars($text, ENT_XML1),
        );
    }

    /**
     * Współrzędne SVG w pikselach 96 dpi (mm × 96/25,4): mPDF tak liczy szerokość tekstu przy text-anchor,
     * więc w jednostkach mm napisy wyśrodkowane uciekałyby w lewo.
     */
    private static function n(float $mm): string
    {
        return self::mm($mm * 96 / 25.4);
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
