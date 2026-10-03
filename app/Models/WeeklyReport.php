<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Montageauftrag: opis części tygodnia dla jednego projektu.
 *
 * @property int $id
 * @property int $work_week_id
 * @property int $project_id
 * @property string|null $performed_work
 * @property string|null $remaining_work
 * @property int|null $legacy_id
 */
#[Fillable(['work_week_id', 'project_id', 'performed_work', 'remaining_work'])]
class WeeklyReport extends Model
{
    use LogsActivity;

    /**
     * Wpisy godzin projektu w tej części tygodnia.
     *
     * @return Builder<TimeEntry>
     */
    public function entries(): Builder
    {
        return TimeEntry::query()
            ->where('work_week_id', $this->work_week_id)
            ->where('project_id', $this->project_id);
    }

    /**
     * Data na dokumencie: ostatni dzień pracy przy projekcie w tej części tygodnia.
     */
    public function reportDate(): ?CarbonImmutable
    {
        $date = $this->entries()->max('work_date');

        return $date === null ? null : CarbonImmutable::parse($date);
    }

    public function isComplete(): bool
    {
        return filled($this->performed_work);
    }

    /**
     * @return BelongsTo<WorkWeek, $this>
     */
    public function workWeek(): BelongsTo
    {
        return $this->belongsTo(WorkWeek::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<MaterialEntry, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(MaterialEntry::class)->orderBy('position')->orderBy('id');
    }
}
