<?php

namespace App\Models;

use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rezystancja uziemienia: RE × Kp ≤ Ra.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $position
 * @property string $name
 * @property string|null $drawing
 * @property string|null $resistance
 * @property string $correction
 * @property string $limit
 */
#[Fillable(['position', 'name', 'drawing', 'resistance', 'correction', 'limit'])]
class MeasurementEarthing extends Model
{
    public function corrected(): ?float
    {
        return $this->resistance === null ? null : (float) $this->resistance * (float) $this->correction;
    }

    public function passes(): ?bool
    {
        return Criteria::earthingPasses($this->resistance === null ? null : (float) $this->resistance, (float) $this->correction, (float) $this->limit);
    }
}
