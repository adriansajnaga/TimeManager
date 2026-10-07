<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Illuminate\Database\Eloquent\Builder;

/**
 * Co widzi klient: projekty swojej firmy, ich godziny (zatwierdzone i w trakcie) i Montageaufträge zatwierdzonych tygodni.
 * Bez stawek, kwot i faktur.
 */
final class ClientPortal
{
    public function __construct(private readonly User $client) {}

    public function contractorId(): int
    {
        abort_unless($this->client->isClient() && $this->client->contractor_id !== null, 403);

        return $this->client->contractor_id;
    }

    /**
     * @return Builder<Project>
     */
    public function projects(): Builder
    {
        return Project::query()->where('contractor_id', $this->contractorId());
    }

    public function authorize(Project $project): void
    {
        abort_unless($project->contractor_id === $this->contractorId(), 404);
    }

    /**
     * Wszystkie godziny projektów firmy klienta — zatwierdzone i jeszcze nierozliczone.
     *
     * @return Builder<TimeEntry>
     */
    public function entries(): Builder
    {
        $contractorId = $this->contractorId();

        return TimeEntry::query()
            ->whereHas('project', fn (Builder $projects) => $projects->where('contractor_id', $contractorId));
    }

    /**
     * Zatwierdzone godziny: wpisy z części tygodni zamkniętych dla firmy klienta.
     *
     * @return Builder<TimeEntry>
     */
    public function approvedEntries(): Builder
    {
        return $this->entries()->whereIn('work_week_id', WorkWeek::query()->closedFor($this->contractorId())->select('id'));
    }

    public function weekIsVisible(WorkWeek $week): bool
    {
        return $week->isClosedFor($this->contractorId());
    }
}
