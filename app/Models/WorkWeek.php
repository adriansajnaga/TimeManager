<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tydzień ISO w obrębie jednego miesiąca — jednostka zamykania i rozliczania.
 * Tydzień na przełomie miesięcy ma dwie części, np. KW 40/2026: 28–30.09 i 01–04.10.
 *
 * @property int $id
 * @property int $iso_year
 * @property int $iso_week
 * @property int $year
 * @property int $month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $invoiced_at
 */
#[Fillable(['iso_year', 'iso_week', 'year', 'month', 'starts_on', 'ends_on'])]
class WorkWeek extends Model
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
            'iso_year' => 'integer',
            'iso_week' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    /**
     * Część tygodnia, do której należy dzień (tworzona przy pierwszym użyciu).
     */
    public static function forDate(CarbonInterface $date): self
    {
        $day = CarbonImmutable::instance($date)->startOfDay();
        $monday = $day->startOfWeek(CarbonInterface::MONDAY);
        $sunday = $monday->addDays(6);

        return static::query()->firstOrCreate([
            'iso_year' => $day->isoWeekYear(),
            'iso_week' => $day->isoWeek(),
            'year' => $day->year,
            'month' => $day->month,
        ], [
            'starts_on' => $monday->max($day->startOfMonth()),
            'ends_on' => $sunday->min($day->endOfMonth()->startOfDay()),
        ]);
    }

    /**
     * Wszystkie dni tygodnia ISO (pon–nd), także te poza tą częścią — jak w nagłówkach tabel.
     *
     * @return list<CarbonImmutable>
     */
    public function weekDays(): array
    {
        $monday = CarbonImmutable::now()->setISODate($this->iso_year, $this->iso_week)->startOfDay();

        return array_map(fn (int $offset) => $monday->addDays($offset), range(0, 6));
    }

    public function includes(CarbonInterface $day): bool
    {
        return $day->betweenIncluded($this->starts_on, $this->ends_on);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Np. „KW 31/2026 (27.07–31.07)”.
     */
    public function label(): string
    {
        return sprintf(
            'KW %d/%d (%s–%s)',
            $this->iso_week,
            $this->iso_year,
            $this->starts_on->format('d.m'),
            $this->ends_on->format('d.m'),
        );
    }

    /**
     * Zafakturowana (rozliczenie albo stara aplikacja) — nie wchodzi do kolejnego rozliczenia.
     */
    public function isInvoiced(): bool
    {
        return $this->invoiced_at !== null;
    }

    public function close(User $user): void
    {
        $this->forceFill(['closed_at' => now(), 'closed_by' => $user->id])->save();
    }

    public function reopen(): void
    {
        $this->forceFill(['closed_at' => null, 'closed_by' => null])->save();
    }

    /**
     * @param  Builder<WorkWeek>  $query
     */
    public function scopeClosed(Builder $query): void
    {
        $query->whereNotNull('closed_at');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<WeeklyReport, $this>
     */
    public function weeklyReports(): HasMany
    {
        return $this->hasMany(WeeklyReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
