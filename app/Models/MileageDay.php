<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Poprawka dnia kilometrówki: km i/lub trasa wpisane ręcznie.
 *
 * @property int $id
 * @property int $user_id
 * @property int $contractor_id
 * @property CarbonImmutable $trip_date
 * @property string|null $km
 * @property string|null $route
 */
#[Fillable(['user_id', 'contractor_id', 'trip_date', 'km', 'route'])]
class MileageDay extends Model
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
            'km' => 'decimal:1',
        ];
    }

    /**
     * Data zapisywana jako Y-m-d (także w SQLite), żeby porównania po dacie były pewne.
     *
     * @return Attribute<CarbonImmutable, CarbonInterface|string>
     */
    protected function tripDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value)->startOfDay(),
            set: fn (CarbonInterface|string $value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }
}
