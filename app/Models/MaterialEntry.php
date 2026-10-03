<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materiał wpisany do Montageauftrag (tabela „Material”). Cena i fakturowanie — faza rozliczeń.
 *
 * @property int $id
 * @property int $weekly_report_id
 * @property int $position
 * @property string $name
 * @property string|null $quantity
 * @property string $unit
 * @property string|null $unit_price_net
 * @property bool $billable
 */
#[Fillable(['weekly_report_id', 'position', 'name', 'quantity', 'unit', 'unit_price_net', 'billable'])]
class MaterialEntry extends Model
{
    use LogsActivity;

    /** Jednostki z kolumny „Stk/m”. */
    public const UNITS = ['Stk', 'm'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price_net' => 'decimal:2',
            'billable' => 'boolean',
        ];
    }

    /**
     * Ilość bez zbędnych zer, np. "12" zamiast "12.000".
     */
    public function quantityLabel(): string
    {
        return $this->quantity === null ? '' : rtrim(rtrim($this->quantity, '0'), '.');
    }

    /**
     * @return BelongsTo<WeeklyReport, $this>
     */
    public function weeklyReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyReport::class);
    }
}
