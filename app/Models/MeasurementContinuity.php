<?php

namespace App\Models;

use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciągłość przewodu ochronnego / połączenia wyrównawczego. Wiersz z obwodem powstaje sam
 * (MeasurementProtocol::syncContinuities), wiersz bez obwodu dopisuje się ręcznie.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int|null $circuit_id
 * @property int $position
 * @property string $name
 * @property string|null $resistance
 * @property string|null $limit
 * @property-read MeasurementCircuit|null $circuit
 */
#[Fillable(['circuit_id', 'position', 'name', 'resistance', 'limit'])]
class MeasurementContinuity extends Model
{
    public function passes(): ?bool
    {
        return Criteria::continuityPasses(
            $this->resistance === null ? null : (float) $this->resistance,
            $this->limitValue(),
        );
    }

    /**
     * Wartość dopuszczalna: wpisana albo — dla obwodu — UL / Ia (PN-HD 60364-4-41, 411.3.2.6).
     */
    public function limitValue(): ?float
    {
        if ($this->limit !== null) {
            return (float) $this->limit;
        }

        return $this->automaticLimit();
    }

    public function automaticLimit(): ?float
    {
        if ($this->circuit === null) {
            return null;
        }

        $protocol = $this->circuit->board->protocol;

        return Criteria::continuityLimit($protocol->touch_voltage, $this->circuit->tripCurrent($protocol));
    }

    /**
     * @return BelongsTo<MeasurementCircuit, $this>
     */
    public function circuit(): BelongsTo
    {
        return $this->belongsTo(MeasurementCircuit::class, 'circuit_id');
    }
}
