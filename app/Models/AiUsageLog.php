<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Zużycie Claude API przez jedno zapytanie (tokeny z pola `usage` odpowiedzi).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 */
#[Fillable(['user_id', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd'])]
class AiUsageLog extends Model
{
    /**
     * Cennik USD za 1 mln tokenów: [wejście, wyjście, odczyt z cache]. Zapis do cache = 1,25 × wejście.
     * Myślenie modelu jest wliczone w tokeny wyjścia.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const PRICES = [
        'claude-opus-5-5' => ['4', '20', '0.20'],
        'claude-sonnet-5-5' => ['2', '10', '0.20'],
        'claude-haiku-4-5' => ['1', '5', '0.10'],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
        ];
    }

    public static function record(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): self
    {
        return static::query()->create([
            'user_id' => Auth::id(),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_read_tokens' => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'cost_usd' => (string) self::cost($model, $input, $output, $cacheRead, $cacheWrite),
        ]);
    }

    public static function cost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): BigDecimal
    {
        // Odpowiedź może podać model z datą wersji — szukamy po prefiksie; nieznany = cennik Opus.
        $prices = self::PRICES['claude-opus-5-5'];

        foreach (self::PRICES as $id => $candidate) {
            if (str_starts_with($model, $id)) {
                $prices = $candidate;
            }
        }

        [$inputPrice, $outputPrice, $cacheReadPrice] = $prices;

        return BigDecimal::of($input)->multipliedBy($inputPrice)
            ->plus(BigDecimal::of($output)->multipliedBy($outputPrice))
            ->plus(BigDecimal::of($cacheRead)->multipliedBy($cacheReadPrice))
            ->plus(BigDecimal::of($cacheWrite)->multipliedBy($inputPrice)->multipliedBy('1.25'))
            ->dividedBy(1000000, 6, RoundingMode::HalfUp);
    }
}
