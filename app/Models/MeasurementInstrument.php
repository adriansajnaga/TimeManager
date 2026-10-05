<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Contracts\Attachable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Przyrząd pomiarowy; świadectwo wzorcowania jako załącznik (dołączane do raportu).
 *
 * @property int $id
 * @property string $name
 * @property string|null $serial_number
 * @property CarbonImmutable|null $calibrated_on
 * @property CarbonImmutable|null $calibration_valid_until
 * @property bool $is_active
 */
#[Fillable(['name', 'serial_number', 'calibrated_on', 'calibration_valid_until', 'is_active'])]
class MeasurementInstrument extends Model implements Attachable
{
    use HasAttachments;

    public function attachmentDirectory(): string
    {
        return 'measurement-instruments/'.$this->id;
    }

    /** „METREL Eurotest AT MI3101, numer seryjny: 16061617”. */
    public function label(): string
    {
        return $this->name.(filled($this->serial_number) ? ', numer seryjny: '.$this->serial_number : '');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['calibrated_on' => 'immutable_date', 'calibration_valid_until' => 'immutable_date', 'is_active' => 'boolean'];
    }
}
