<?php

namespace App\Models;

use App\Enums\ProtectionType;
use App\Services\Measurements\Criteria;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Obwód rozdzielnicy: zabezpieczenie (Ia, Za), przewód, RCD i rezystancja izolacji par żył.
 *
 * @property int $id
 * @property int $board_id
 * @property int $position
 * @property string|null $number
 * @property string $name
 * @property int $phases
 * @property string $phase Faza obwodu jednofazowego: L1, L2 albo L3
 * @property ProtectionType|null $protection_type
 * @property string|null $protection_current
 * @property string|null $trip_current_override
 * @property string|null $cable
 * @property int|null $rcd_id
 * @property int $insulation_voltage
 * @property array<string, string>|null $insulation
 * @property-read MeasurementBoard $board
 * @property-read MeasurementRcd|null $rcd
 */
#[Fillable(['position', 'number', 'name', 'phases', 'phase', 'protection_type', 'protection_current', 'trip_current_override', 'cable', 'rcd_id', 'insulation_voltage', 'insulation'])]
class MeasurementCircuit extends Model
{
    public const PAIRS_SINGLE = ['L-N', 'L-PE', 'N-PE'];

    public const PAIRS_THREE = ['L1-L2', 'L2-L3', 'L3-L1', 'L1-N', 'L2-N', 'L3-N', 'L1-PE', 'L2-PE', 'L3-PE', 'N-PE'];

    /** Kolumny tabeli izolacji w raporcie (trójfazowe); jednofazowe L-N/L-PE trafiają pod L1-N/L1-PE. */
    public const REPORT_PAIRS = self::PAIRS_THREE;

    /**
     * @return list<string>
     */
    public function pairs(): array
    {
        return $this->phases === 3 ? self::PAIRS_THREE : self::PAIRS_SINGLE;
    }

    public const PHASES = ['L1', 'L2', 'L3'];

    /**
     * Para do wyświetlenia: w obwodzie jednofazowym „L” zastąpione jego fazą (L-N → L2-N).
     */
    public function pairLabel(string $pair): string
    {
        return $this->phases === 3 ? $pair : preg_replace('/^L(?=-)/', $this->phase, $pair);
    }

    /**
     * Odczyty izolacji pod kolumnami raportu (L1-L2 … N-PE): jednofazowe trafiają pod swoją fazę.
     *
     * @return array<string, string>
     */
    public function reportReadings(): array
    {
        $readings = $this->insulation ?? [];

        if ($this->phases === 3) {
            return $readings;
        }

        $mapped = [];

        foreach ($readings as $pair => $value) {
            $mapped[$this->pairLabel($pair)] = $value;
        }

        return $mapped;
    }

    public function tripCurrent(MeasurementProtocol $protocol): ?float
    {
        return Criteria::tripCurrent(
            $this->protection_type,
            $this->protection_current === null ? null : (float) $this->protection_current,
            $protocol->disconnectionTime(),
            $this->trip_current_override === null ? null : (float) $this->trip_current_override,
            distribution: $this->board->isSupply(),
        );
    }

    public function allowedImpedance(MeasurementProtocol $protocol): ?float
    {
        return Criteria::allowedImpedance($protocol->phase_voltage, $this->tripCurrent($protocol));
    }

    /** „B16”, „gG 25”. */
    public function protectionLabel(): string
    {
        if ($this->protection_type === null) {
            return '';
        }

        $current = $this->protection_current === null ? '' : rtrim(rtrim((string) $this->protection_current, '0'), '.');

        return $this->protection_type === ProtectionType::GG ? trim('gG '.$current) : $this->protection_type->value.$current;
    }

    /**
     * Najsłabszy wynik izolacji obwodu: null — brak pomiarów.
     */
    public function insulationPasses(): ?bool
    {
        $required = Criteria::requiredInsulation($this->insulation_voltage);
        $results = array_filter(array_map(
            fn ($reading) => Criteria::insulationPasses((string) $reading, $required),
            $this->insulation ?? [],
        ), fn ($result) => $result !== null);

        return $results === [] ? null : ! in_array(false, $results, true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['protection_type' => ProtectionType::class, 'insulation' => 'array', 'phases' => 'integer', 'insulation_voltage' => 'integer'];
    }

    /**
     * @return BelongsTo<MeasurementBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(MeasurementBoard::class, 'board_id');
    }

    /**
     * @return BelongsTo<MeasurementRcd, $this>
     */
    public function rcd(): BelongsTo
    {
        return $this->belongsTo(MeasurementRcd::class, 'rcd_id');
    }

    /**
     * @return HasMany<MeasurementPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(MeasurementPoint::class, 'circuit_id')->orderBy('position');
    }
}
