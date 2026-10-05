<?php

namespace App\Models;

use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciągłość przewodu ochronnego / połączenia wyrównawczego.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $position
 * @property string $name
 * @property string|null $resistance
 * @property string|null $limit
 */
#[Fillable(['position', 'name', 'resistance', 'limit'])]
class MeasurementContinuity extends Model
{
    public function passes(): ?bool
    {
        return Criteria::continuityPasses(
            $this->resistance === null ? null : (float) $this->resistance,
            $this->limit === null ? null : (float) $this->limit,
        );
    }
}
