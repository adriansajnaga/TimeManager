<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rozdzielnica (R1, UV1) albo WLZ (kind = supply: pętla L-N/L-PE/L-L odcinka zasilającego).
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $position
 * @property string $kind
 * @property string $name
 * @property string|null $description
 * @property array<mixed>|null $layout
 * @property-read MeasurementProtocol $protocol
 */
#[Fillable(['position', 'kind', 'name', 'description', 'layout'])]
class MeasurementBoard extends Model
{
    public const KIND_BOARD = 'board';

    public const KIND_SUPPLY = 'supply';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    public function isSupply(): bool
    {
        return $this->kind === self::KIND_SUPPLY;
    }

    /**
     * @return BelongsTo<MeasurementProtocol, $this>
     */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(MeasurementProtocol::class, 'protocol_id');
    }

    /**
     * @return HasMany<MeasurementCircuit, $this>
     */
    public function circuits(): HasMany
    {
        return $this->hasMany(MeasurementCircuit::class, 'board_id')->orderBy('position');
    }

    /**
     * @return HasMany<MeasurementRcd, $this>
     */
    public function rcds(): HasMany
    {
        return $this->hasMany(MeasurementRcd::class, 'board_id')->orderBy('position');
    }
}
