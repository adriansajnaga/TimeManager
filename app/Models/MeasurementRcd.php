<?php

namespace App\Models;

use App\Enums\RcdType;
use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wyłącznik różnicowoprądowy: pomiar czasu i prądu zadziałania przy 1×IΔn, napięcie dotyku, przycisk TEST.
 *
 * @property int $id
 * @property int $board_id
 * @property int $position
 * @property string $designation
 * @property string|null $model
 * @property RcdType $type
 * @property bool $selective
 * @property string|null $rated_current
 * @property int $rated_residual
 * @property string|null $trip_time
 * @property string|null $trip_current
 * @property string|null $contact_voltage
 * @property bool $test_button
 * @property-read MeasurementBoard $board
 */
#[Fillable(['position', 'designation', 'model', 'type', 'selective', 'rated_current', 'rated_residual', 'trip_time', 'trip_current', 'contact_voltage', 'test_button'])]
class MeasurementRcd extends Model
{
    /**
     * Niespełnione warunki (pusta lista = pozytywna), null — bez pomiarów.
     *
     * @return list<string>|null
     */
    public function failures(int $touchVoltage): ?array
    {
        return Criteria::rcdFailures(
            $this->type,
            $this->selective,
            $this->rated_residual,
            self::number($this->trip_time),
            self::number($this->trip_current),
            self::number($this->contact_voltage),
            $touchVoltage,
            $this->test_button,
        );
    }

    public function passes(int $touchVoltage): ?bool
    {
        $failures = $this->failures($touchVoltage);

        return $failures === null ? null : $failures === [];
    }

    private static function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => RcdType::class, 'selective' => 'boolean', 'test_button' => 'boolean', 'rated_residual' => 'integer'];
    }

    /**
     * @return BelongsTo<MeasurementBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(MeasurementBoard::class, 'board_id');
    }
}
