<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Ustawienia asystenta AI (jeden wiersz). Klucz API jest szyfrowany kluczem APP_KEY —
 * jego utrata oznacza utratę zapisanego klucza.
 *
 * @property int $id
 * @property string|null $api_key
 * @property string $model
 * @property CarbonImmutable|null $verified_at
 * @property string|null $instructions
 * @property string|null $balance_usd
 * @property CarbonImmutable|null $balance_set_at
 */
#[Fillable(['api_key', 'model', 'instructions', 'balance_usd', 'balance_set_at'])]
#[Hidden(['api_key'])]
class AiSetting extends Model
{
    use LogsActivity;

    /** Modele do wyboru (identyfikatory API Claude). */
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-4-5' => 'Claude Haiku 4.5',
    ];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
            'balance_usd' => 'decimal:2',
            'balance_set_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self(['model' => self::DEFAULT_MODEL]);
    }

    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }

    /**
     * Koszt zapytań od wpisania salda (USD).
     */
    public function spentSinceBalance(): BigDecimal
    {
        $sum = AiUsageLog::query()
            ->when($this->balance_set_at !== null, fn ($query) => $query->where('created_at', '>=', $this->balance_set_at))
            ->sum('cost_usd');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * Szacowane saldo konta Claude: wpisane saldo minus koszty od tamtej chwili (null = saldo nie wpisane).
     */
    public function estimatedBalance(): ?BigDecimal
    {
        return $this->balance_usd === null ? null : BigDecimal::of($this->balance_usd)->minus($this->spentSinceBalance());
    }
}
