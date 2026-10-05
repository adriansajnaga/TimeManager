<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Część tygodnia zamknięta dla jednego klienta (jego godziny, raporty i kilometrówka są zablokowane).
 *
 * @property int $id
 * @property int $work_week_id
 * @property int $contractor_id
 * @property CarbonImmutable $closed_at
 * @property int|null $closed_by
 */
#[Fillable(['work_week_id', 'contractor_id', 'closed_at', 'closed_by'])]
class WorkWeekClosure extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<WorkWeek, $this>
     */
    public function workWeek(): BelongsTo
    {
        return $this->belongsTo(WorkWeek::class);
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
