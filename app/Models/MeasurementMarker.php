<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Numerowany znacznik na rzucie (obrazie załączonym do protokołu). Obejmuje jeden lub kilka punktów
 * pomiarowych — np. 2–5 gniazd obok siebie to jeden numer, żeby rzut był czytelny.
 *
 * @property int $id
 * @property int $protocol_id
 * @property int $attachment_id
 * @property int|null $board_id Znacznik rozdzielnicy (prostokąt z nazwą) zamiast punktu
 * @property int $number
 * @property string $x Pozycja w % szerokości obrazu
 * @property string $y Pozycja w % wysokości obrazu
 * @property-read Attachment $attachment
 * @property-read MeasurementBoard|null $board
 */
#[Fillable(['attachment_id', 'board_id', 'number', 'x', 'y'])]
class MeasurementMarker extends Model
{
    /** Kolejny numer znacznika w protokole. */
    public static function nextNumber(MeasurementProtocol $protocol): int
    {
        return (int) static::query()->where('protocol_id', $protocol->id)->whereNull('board_id')->max('number') + 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function isBoard(): bool
    {
        return $this->board_id !== null;
    }

    /**
     * @return BelongsTo<MeasurementBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(MeasurementBoard::class, 'board_id');
    }

    /**
     * Symbol na rzucie wg pierwszego punktu (kilka gniazd obok siebie to jeden znacznik).
     *
     * @return 'socket'|'socket3'|'light'|'point'
     */
    public function kind(): string
    {
        return $this->points->sortBy('position')->first()?->kind() ?? 'point';
    }

    /**
     * @return HasMany<MeasurementPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(MeasurementPoint::class, 'marker_id');
    }
}
