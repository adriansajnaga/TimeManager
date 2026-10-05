<?php

namespace App\Livewire\Forms;

use App\Enums\Permission;
use App\Enums\WorkType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class TimeEntryForm extends Form
{
    public ?TimeEntry $entry = null;

    public ?string $user_id = null;

    public ?string $project_id = null;

    public string $work_date = '';

    public string $start_time = '';

    public string $end_time = '';

    public int|string $break_minutes = 45;

    public string $work_type = 'montage';

    public string $description = '';

    public bool $count_mileage = false;

    public function setEntry(?TimeEntry $entry, ?string $date = null, ?int $projectId = null, ?int $userId = null): void
    {
        $this->resetValidation();
        $this->entry = $entry;

        if ($entry === null) {
            $this->reset('start_time', 'end_time', 'description');
            $this->break_minutes = 45;
            $this->work_type = WorkType::Montage->value;
            $this->user_id = (string) ($userId ?? Auth::id());
            $this->work_date = $date ?? CarbonImmutable::today()->toDateString();
            $this->project_id = $projectId !== null ? (string) $projectId : null;
            $this->applyProjectDefaults();

            return;
        }

        $this->fill([
            'user_id' => (string) $entry->user_id,
            'project_id' => (string) $entry->project_id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => $entry->startLabel(),
            'end_time' => $entry->endLabel(),
            'break_minutes' => $entry->break_minutes,
            'work_type' => $entry->work_type->value,
            'description' => (string) $entry->description,
            'count_mileage' => $entry->count_mileage,
        ]);
    }

    /**
     * Nowy wpis dziedziczy domyślny stan kilometrówki po projekcie.
     */
    public function applyProjectDefaults(): void
    {
        $project = $this->project_id ? Project::find($this->project_id) : null;

        $this->count_mileage = $project->mileage_default ?? false;
    }

    /**
     * Godziny wyliczone z bieżących pól albo null, gdy czasy są niepełne lub błędne.
     */
    public function calculatedHours(): ?string
    {
        if (! $this->validTime($this->start_time) || ! $this->validTime($this->end_time, allowMidnight: true)) {
            return null;
        }

        $hours = TimeEntry::calculateHours($this->start_time, $this->end_time, (int) $this->break_minutes);

        return (float) $hours > 0 ? $hours : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $actor = Auth::user();
        $canLogForOthers = $actor?->hasPermission(Permission::ViewAllTimeEntries) ?? false;
        $owner = User::find($this->user_id) ?? $actor;

        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id'), $canLogForOthers ? 'nullable' : Rule::in([(string) $actor?->id])],
            'project_id' => [
                'required', 'integer',
                Rule::in($owner ? Project::query()->availableFor($owner)->pluck('id')->map(fn (int $id) => (string) $id)->all() : []),
            ],
            'work_date' => ['required', 'date_format:Y-m-d', $this->openWeekRule()],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'],
            'end_time' => ['required', 'regex:/^(([01]\d|2[0-3]):(00|15|30|45)|24:00)$/', $this->durationRule(), $this->overlapRule()],
            'break_minutes' => ['required', 'integer', Rule::in(TimeEntry::BREAKS)],
            'work_type' => ['required', Rule::enum(WorkType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'count_mileage' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'project_id.in' => __('This project is not available for the selected person.'),
            'start_time.regex' => __('Use a full quarter of an hour, e.g. 07:15.'),
            'end_time.regex' => __('Use a full quarter of an hour, e.g. 16:45.'),
        ];
    }

    public function save(): TimeEntry
    {
        $this->validate();

        $entry = $this->entry ?? new TimeEntry;

        $entry->fill([
            'user_id' => (int) $this->user_id,
            'project_id' => (int) $this->project_id,
            'work_date' => $this->work_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'break_minutes' => (int) $this->break_minutes,
            'work_type' => $this->work_type,
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'count_mileage' => $this->count_mileage,
        ])->save();

        return $this->entry = $entry;
    }

    private function validTime(string $time, bool $allowMidnight = false): bool
    {
        return preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $time) === 1
            || ($allowMidnight && $time === '24:00');
    }

    private function openWeekRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $value);

            if ($date === null) {
                return;
            }

            $week = WorkWeek::query()
                ->where('iso_year', $date->isoWeekYear())
                ->where('iso_week', $date->isoWeek())
                ->where('year', $date->year)
                ->where('month', $date->month)
                ->first();

            // Zamknięcie dotyczy klienta projektu — godziny innego klienta można dopisać.
            $contractorId = $this->project_id ? Project::query()->whereKey($this->project_id)->value('contractor_id') : null;

            if ($week !== null && $contractorId !== null && $week->isClosedFor((int) $contractorId)) {
                $fail(__('This week is closed for this client. Ask the administrator to reopen it.'));
            }
        };
    }

    private function durationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! $this->validTime($this->start_time) || ! $this->validTime((string) $value, allowMidnight: true)) {
                return;
            }

            if ($this->calculatedHours() === null) {
                $fail(__('The end time must be later than the start time plus the break.'));
            }
        };
    }

    private function overlapRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($this->calculatedHours() === null || $this->user_id === null || $this->work_date === '') {
                return;
            }

            $start = TimeEntry::minutesOfDay($this->start_time);
            $end = TimeEntry::minutesOfDay($this->end_time);

            $overlapping = TimeEntry::query()
                ->where('user_id', $this->user_id)
                ->whereDate('work_date', $this->work_date)
                ->when($this->entry !== null, fn ($query) => $query->whereKeyNot($this->entry->id))
                ->get(['start_time', 'end_time'])
                ->first(fn (TimeEntry $other) => $start < TimeEntry::minutesOfDay($other->end_time)
                    && TimeEntry::minutesOfDay($other->start_time) < $end);

            if ($overlapping !== null) {
                $fail(__('This time overlaps another entry (:from–:to).', [
                    'from' => $overlapping->startLabel(),
                    'to' => $overlapping->endLabel(),
                ]));
            }
        };
    }
}
