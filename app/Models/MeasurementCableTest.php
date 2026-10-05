<?php

namespace App\Models;

use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Izolacja kabla (WLZ, zasilanie podrozdzielnicy): odczyty par żył, napięcie probiercze, Ra.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $position
 * @property string $name
 * @property string|null $cable_type
 * @property string|null $cross_section
 * @property string|null $length
 * @property string|null $temperature
 * @property int $test_voltage
 * @property array<string, string>|null $values
 * @property string $limit
 */
#[Fillable(['position', 'name', 'cable_type', 'cross_section', 'length', 'temperature', 'test_voltage', 'values', 'limit'])]
class MeasurementCableTest extends Model
{
    /** Odcinki jak w protokole WLZ: wszystkie żyły do ziemi i pary. */
    public const PAIRS = ['(L1-L2-L3-PE-N) – E', 'L1-L2', 'L2-L3', 'L3-L1', 'L1-N', 'L2-N', 'L3-N', 'L1-PE', 'L2-PE', 'L3-PE', 'N-PE'];

    public function passes(string $pair): ?bool
    {
        return Criteria::insulationPasses($this->values[$pair] ?? null, (float) $this->limit);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['values' => 'array', 'test_voltage' => 'integer'];
    }
}
