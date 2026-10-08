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
 * @property string|null $type 'bonding' — główna szyna wyrównawcza (GSW)
 * @property int $number
 * @property string $x Pozycja w % szerokości obrazu
 * @property string $y Pozycja w % wysokości obrazu
 * @property int $rotation Obrót symbolu w stopniach (0, 90, 180, 270)
 * @property-read Attachment $attachment
 * @property-read MeasurementBoard|null $board
 */
#[Fillable(['attachment_id', 'board_id', 'type', 'number', 'x', 'y', 'rotation'])]
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
        return ['number' => 'integer', 'rotation' => 'integer'];
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public const TYPE_BONDING = 'bonding';

    /** Kolor GSW na rzucie (żółto-zielony przewód ochronny — tu zielony, czytelny na rysunku). */
    public const BONDING_COLOR = '#15803d';

    public function isBoard(): bool
    {
        return $this->board_id !== null;
    }

    public function isBonding(): bool
    {
        return $this->type === self::TYPE_BONDING;
    }

    /** Znacznik punktów pomiarowych (nie rozdzielnica ani GSW). */
    public function isPoint(): bool
    {
        return ! $this->isBoard() && ! $this->isBonding();
    }

    /**
     * @return BelongsTo<MeasurementBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(MeasurementBoard::class, 'board_id');
    }

    /** Opisy symboli w legendzie rzutu (klucze tłumaczeń; w PDF zawsze po polsku). */
    public const LEGEND = [
        'socket' => 'Single-phase socket outlet',
        'socket3' => 'Three-phase socket outlet',
        'light' => 'Lighting point',
        'point' => 'Other measuring point',
    ];

    /**
     * Pozycje legendy dla znaczników jednego rzutu: rodzaje punktów, które na nim są, rozdzielnica i GSW.
     *
     * @param  iterable<MeasurementMarker>  $markers
     * @return list<string> klucze LEGEND oraz 'board' / 'bonding'
     */
    public static function legendItems(iterable $markers): array
    {
        $items = [];

        foreach ($markers as $marker) {
            $items[] = $marker->isBoard() ? 'board' : ($marker->isBonding() ? 'bonding' : $marker->kind());
        }

        $order = [...array_keys(self::LEGEND), 'board', 'bonding'];

        return array_values(array_filter($order, fn (string $item) => in_array($item, $items, true)));
    }

    /** Kolory symboli na rzucie — czerwona jest tylko rozdzielnica. */
    public const COLORS = [
        'socket' => '#2563eb',
        'socket3' => '#7c3aed',
        'light' => '#d97706',
        'point' => '#059669',
    ];

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
