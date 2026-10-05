<?php

namespace App\Models;

use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punkt pomiaru pętli zwarcia: gniazdo (G1), oprawa (O1), faza (L1) — Zs L-PE i opcjonalnie N-PE.
 * W WLZ symbol to odcinek (L1-N, L1-PE, L1-L2); dla pętli L-L nie liczymy Ik.
 *
 * @property int $id
 * @property int $circuit_id
 * @property int $position
 * @property string|null $symbol
 * @property string|null $location
 * @property string $loop
 * @property string|null $impedance
 * @property string|null $impedance_npe
 * @property-read MeasurementCircuit $circuit
 */
#[Fillable(['position', 'symbol', 'location', 'loop', 'impedance', 'impedance_npe'])]
class MeasurementPoint extends Model
{
    public const LOOPS = ['L-PE', 'L-N', 'L-L'];

    public function isLineToLine(): bool
    {
        return $this->loop === 'L-L';
    }

    public function shortCircuitCurrent(MeasurementProtocol $protocol, bool $npe = false): ?float
    {
        if ($this->isLineToLine()) {
            return null;
        }

        return Criteria::shortCircuitCurrent($protocol->phase_voltage, self::number($npe ? $this->impedance_npe : $this->impedance));
    }

    /**
     * Zs ≤ Za; dla pętli L-L wystarczy pomiar.
     */
    public function passes(?float $allowed, bool $npe = false): ?bool
    {
        $impedance = self::number($npe ? $this->impedance_npe : $this->impedance);

        if ($this->isLineToLine()) {
            return $impedance === null ? null : true;
        }

        return Criteria::loopPasses($impedance, $allowed);
    }

    private static function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return BelongsTo<MeasurementCircuit, $this>
     */
    public function circuit(): BelongsTo
    {
        return $this->belongsTo(MeasurementCircuit::class, 'circuit_id');
    }
}
