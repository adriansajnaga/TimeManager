<?php

namespace App\Documents;

use App\Enums\Language;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Formaty dat, liczb i kwot w języku dokumentu klienta.
 */
final class DocumentFormat
{
    public function __construct(public readonly Language $language) {}

    public function date(?CarbonInterface $date): string
    {
        return $date === null ? '' : $date->format($this->language === Language::English ? 'd/m/Y' : 'd.m.Y');
    }

    /**
     * Godziny bez zbędnych zer: "10.75" → "10,75" (DE/PL), "8.00" → "8".
     */
    public function hours(BigDecimal|string|null $hours): string
    {
        if ($hours === null || BigDecimal::of($hours)->isZero()) {
            return '';
        }

        $value = BigDecimal::of($hours)->toScale(2, RoundingMode::HalfUp);
        $text = rtrim(rtrim((string) $value, '0'), '.');

        return $this->language === Language::English ? $text : str_replace('.', ',', $text);
    }

    /**
     * @param  int<0, max>  $decimals
     */
    public function number(BigDecimal|string $value, int $decimals = 2): string
    {
        $value = BigDecimal::of($value)->toScale($decimals, RoundingMode::HalfUp);
        [$integer, $fraction] = array_pad(explode('.', ltrim((string) $value, '-')), 2, '');
        $sign = $value->isNegative() ? '-' : '';

        [$thousands, $decimal] = match ($this->language) {
            Language::German => ['.', ','],
            Language::Polish => ["\u{00A0}", ','],
            Language::English => [',', '.'],
        };

        // Grupy po 3 cyfry od prawej; separator łączony dopiero po odwróceniu (bywa wielobajtowy).
        $groups = array_map('strrev', array_reverse(str_split(strrev($integer), 3)));
        $integer = implode($thousands, $groups);

        return $sign.$integer.($decimals > 0 ? $decimal.$fraction : '');
    }

    public function money(BigDecimal|string $amount, string $currency): string
    {
        $symbol = match (strtoupper($currency)) {
            'EUR' => '€',
            'PLN' => 'zł',
            default => strtoupper($currency),
        };

        return $this->number($amount)."\u{00A0}".$symbol;
    }
}
