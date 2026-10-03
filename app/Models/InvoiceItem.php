<?php

namespace App\Models;

use App\Enums\VatCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja faktury. W korekcie wiersze `is_before` opisują stan przed korektą.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property bool $is_before
 * @property string $name
 * @property string|null $unit
 * @property string $quantity
 * @property string $unit_price
 * @property VatCode $vat_code
 * @property string $net
 */
#[Fillable(['position', 'is_before', 'name', 'unit', 'quantity', 'unit_price', 'vat_code', 'net'])]
class InvoiceItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_before' => 'boolean',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'vat_code' => VatCode::class,
            'net' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item) {
            $item->net = (string) self::netOf($item->quantity, $item->unit_price);
        });
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public static function netOf(BigDecimal|string|null $quantity, BigDecimal|string|null $unitPrice): BigDecimal
    {
        return BigDecimal::of($quantity ?? '0')->multipliedBy($unitPrice ?? '0')->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Ilość bez zbędnych zer: "1.0000" → "1", "10.7500" → "10.75".
     */
    public function quantityLabel(): string
    {
        return rtrim(rtrim($this->quantity, '0'), '.');
    }
}
