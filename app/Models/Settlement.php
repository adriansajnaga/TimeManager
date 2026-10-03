<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rozliczenie godzin i kilometrów klienta z zamkniętych części tygodni.
 *
 * @property int $id
 * @property int $contractor_id
 * @property int|null $invoice_id
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $hours
 * @property string $hourly_rate
 * @property string $km
 * @property string $km_rate
 * @property string $amount
 * @property string $currency
 * @property int|null $created_by
 * @property-read Contractor $contractor
 * @property-read Invoice|null $invoice
 * @property-read Collection<int, WorkWeek> $workWeeks
 */
#[Fillable(['contractor_id', 'invoice_id', 'period_from', 'period_to', 'hours', 'hourly_rate', 'km', 'km_rate', 'amount', 'currency', 'created_by'])]
class Settlement extends Model
{
    use LogsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'km' => 'decimal:1',
            'km_rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsToMany<WorkWeek, $this>
     */
    public function workWeeks(): BelongsToMany
    {
        return $this->belongsToMany(WorkWeek::class)->orderBy('starts_on');
    }
}
