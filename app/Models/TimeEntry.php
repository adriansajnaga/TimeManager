<?php

namespace App\Models;

use App\Enums\WorkType;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wpis czasu pracy: dzień, start, koniec i przerwa. Godziny i część tygodnia
 * są wyliczane przy zapisie, więc zawsze zgadzają się z czasami.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int $work_week_id
 * @property CarbonImmutable $work_date
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property string $hours
 * @property WorkType $work_type
 * @property string|null $description
 * @property bool $count_mileage
 * @property int|null $legacy_id
 */
#[Fillable([
    'user_id', 'project_id', 'work_date', 'start_time', 'end_time', 'break_minutes',
    'work_type', 'description', 'count_mileage',
])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory, LogsActivity;

    /** Dozwolone przerwy w minutach (jak w starej aplikacji). */
    public const BREAKS = [45, 30, 15, 0];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'hours' => 'decimal:2',
            'work_type' => WorkType::class,
            'count_mileage' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TimeEntry $entry) {
            $entry->start_time = self::normalizeTime($entry->start_time);
            $entry->end_time = self::normalizeTime($entry->end_time);
            $entry->hours = self::calculateHours($entry->start_time, $entry->end_time, $entry->break_minutes);
            $entry->work_week_id = WorkWeek::forDate($entry->work_date)->id;
        });
    }

    /**
     * Godziny jako tekst z dwoma miejscami, np. "10.25" dla 06:00–17:00 z przerwą 45 min.
     */
    public static function calculateHours(string $start, string $end, int $breakMinutes): string
    {
        $minutes = self::minutesOfDay($end) - self::minutesOfDay($start) - $breakMinutes;

        return number_format($minutes / 60, 2, '.', '');
    }

    /**
     * "07:30" / "07:30:00" → 450. Obsługuje "24:00" jako koniec dnia.
     */
    public static function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 60 + $minutes;
    }

    private static function normalizeTime(string $time): string
    {
        return substr($time, 0, 5).':00';
    }

    /**
     * Dzień pracy zapisywany zawsze jako "Y-m-d" (rzutowanie `date` zapisałoby w SQLite także godzinę,
     * przez co porównania dat działałyby inaczej niż w MySQL).
     *
     * @return Attribute<CarbonImmutable, CarbonInterface|string>
     */
    protected function workDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value)->startOfDay(),
            set: fn (CarbonInterface|string $value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function startLabel(): string
    {
        return substr($this->start_time, 0, 5);
    }

    public function endLabel(): string
    {
        return substr($this->end_time, 0, 5);
    }

    public function isLocked(): bool
    {
        return $this->workWeek->isClosed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<WorkWeek, $this>
     */
    public function workWeek(): BelongsTo
    {
        return $this->belongsTo(WorkWeek::class);
    }
}
