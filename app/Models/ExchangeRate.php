<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Średni kurs NBP (tabela A) z dnia publikacji.
 *
 * @property int $id
 * @property string $currency
 * @property CarbonImmutable $effective_date
 * @property string $rate
 * @property string $table_number
 */
#[Fillable(['currency', 'effective_date', 'rate', 'table_number'])]
class ExchangeRate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'rate' => 'decimal:4',
        ];
    }
}
