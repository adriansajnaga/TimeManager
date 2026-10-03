<?php

namespace App\Models;

use App\Enums\KsefEnvironment;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Połączenie z KSeF (jeden wiersz). Token jest szyfrowany kluczem APP_KEY.
 *
 * @property int $id
 * @property KsefEnvironment $environment
 * @property string|null $nip
 * @property string|null $token
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $synced_until
 */
#[Fillable(['environment', 'nip', 'token', 'verified_at', 'synced_until'])]
#[Hidden(['token'])]
class KsefSetting extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => KsefEnvironment::class,
            'token' => 'encrypted',
            'verified_at' => 'datetime',
            'synced_until' => 'date',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'environment' => KsefEnvironment::Test,
            'nip' => CompanySetting::current()->nip,
        ]);
    }

    public function isConfigured(): bool
    {
        return filled($this->nip) && filled($this->token);
    }

    public function baseUrl(): string
    {
        return $this->environment->baseUrl();
    }
}
