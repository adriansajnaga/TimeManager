<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
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
 */
#[Fillable(['api_key', 'model'])]
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
}
