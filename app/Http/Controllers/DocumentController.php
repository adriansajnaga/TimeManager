<?php

namespace App\Http\Controllers;

use App\Documents\Document;
use App\Documents\MileageAllowance;
use App\Documents\Montageauftrag;
use App\Documents\PdfRenderer;
use App\Documents\Stundennachweis;
use App\Documents\Stundenzettel;
use App\Enums\Permission;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Services\Mileage\MileageCalculator;
use App\Services\Mileage\MileageTrip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * PDF dokumentów roboczych: Montageauftrag, Stundennachweis, Stundenzettel.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    public function montageauftrag(Request $request, WorkWeek $workWeek, Project $project): Response
    {
        $this->authorizeProject($request->user(), $project);

        $report = WeeklyReport::query()->firstOrCreate(['work_week_id' => $workWeek->id, 'project_id' => $project->id]);
        abort_unless($report->entries()->exists(), 404);

        return $this->pdf([new Montageauftrag($report)], __('Weekly report'));
    }

    /**
     * Wszystkie Montageaufträge części tygodnia (dla pracownika — jego projekty).
     */
    public function montageauftraege(Request $request, WorkWeek $workWeek): Response
    {
        $user = $request->user();

        $projectIds = TimeEntry::query()
            ->where('work_week_id', $workWeek->id)
            ->when(! $user->hasPermission(Permission::ViewAllTimeEntries), fn ($query) => $query->whereHas('project.users', fn ($users) => $users->whereKey($user->id)))
            ->distinct()
            ->pluck('project_id');

        abort_if($projectIds->isEmpty(), 404);

        $documents = array_values(Project::query()
            ->whereIn('id', $projectIds)
            ->orderBy('number')
            ->get()
            ->map(fn (Project $project) => new Montageauftrag(
                WeeklyReport::query()->firstOrCreate(['work_week_id' => $workWeek->id, 'project_id' => $project->id])
            ))
            ->all());

        return $this->pdf($documents, __('Weekly reports'), sprintf('Montageauftraege_KW%02d-%d_%02d.pdf', $workWeek->iso_week, $workWeek->iso_year, $workWeek->month));
    }

    public function stundennachweis(Request $request, WorkWeek $workWeek, User $user, Contractor $contractor): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->is($user) || $viewer->hasPermission(Permission::ViewAllTimeEntries), 403);

        $document = new Stundennachweis($workWeek, $user, $contractor);
        abort_if($document->entries()->isEmpty(), 404);

        return $this->pdf([$document], __('Weekly time record'));
    }

    /**
     * Stundenzettel dla klienta z wybranych, zamkniętych części tygodni.
     */
    public function stundenzettel(Request $request): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'integer', 'exists:contractors,id'],
            'weeks' => ['required', 'array', 'min:1'],
            'weeks.*' => ['integer', 'exists:work_weeks,id'],
        ]);

        /** @var Collection<int, WorkWeek> $weeks */
        $weeks = WorkWeek::query()->closedFor((int) $validated['client'])->whereIn('id', $validated['weeks'])->get();
        abort_if($weeks->isEmpty(), 404);

        $document = new Stundenzettel(Contractor::query()->findOrFail((int) $validated['client']), $weeks);

        return $this->pdf([$document], __('Timesheet'));
    }

    /**
     * Montageaufträge klienta z wybranych części tygodni (podgląd przed rozliczeniem).
     */
    public function reports(Request $request): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'integer', 'exists:contractors,id'],
            'weeks' => ['required', 'array', 'min:1'],
            'weeks.*' => ['integer', 'exists:work_weeks,id'],
        ]);

        $documents = array_values(WeeklyReport::query()
            ->with(['project', 'workWeek'])
            ->whereIn('work_week_id', $validated['weeks'])
            ->whereHas('project', fn ($projects) => $projects->where('contractor_id', (int) $validated['client']))
            ->get()
            ->filter(fn (WeeklyReport $report) => $report->entries()->exists())
            ->sortBy([fn (WeeklyReport $a, WeeklyReport $b) => $a->workWeek->starts_on <=> $b->workWeek->starts_on, fn (WeeklyReport $a, WeeklyReport $b) => strcmp($a->project->number, $b->project->number)])
            ->map(fn (WeeklyReport $report) => new Montageauftrag($report))
            ->all());

        abort_if($documents === [], 404);

        return $this->pdf($documents, __('Weekly reports'), 'Montageauftraege_'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Kilometrówka klienta z wybranych, zamkniętych części tygodni (osobny dokument dla każdej osoby).
     */
    public function mileage(Request $request): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'integer', 'exists:contractors,id'],
            'weeks' => ['required', 'array', 'min:1'],
            'weeks.*' => ['integer', 'exists:work_weeks,id'],
        ]);

        /** @var Collection<int, WorkWeek> $weeks */
        $weeks = WorkWeek::query()->closedFor((int) $validated['client'])->whereIn('id', $validated['weeks'])->get();
        $client = Contractor::query()->findOrFail((int) $validated['client']);

        $documents = array_values(collect(app(MileageCalculator::class)->trips($weeks, $client))
            ->map(fn (MileageTrip $trip) => $trip->user)
            ->unique('id')
            ->map(fn (User $user) => new MileageAllowance($client, $weeks, $user))
            ->all());

        abort_if($documents === [], 404);

        return $this->pdf($documents, __('Mileage allowance'));
    }

    private function authorizeProject(User $user, Project $project): void
    {
        abort_unless(
            $user->hasPermission(Permission::ViewAllTimeEntries)
                || ($user->hasPermission(Permission::LogOwnTime) && $project->users()->whereKey($user->id)->exists()),
            403,
        );
    }

    /**
     * @param  list<Document>  $documents
     */
    private function pdf(array $documents, string $title, ?string $filename = null): Response
    {
        $filename ??= $documents[0]->filename();

        return response($this->renderer->render($documents, $title), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
