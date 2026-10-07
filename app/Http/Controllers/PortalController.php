<?php

namespace App\Http\Controllers;

use App\Documents\Montageauftrag;
use App\Documents\PdfRenderer;
use App\Models\Project;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use App\Services\ClientPortal;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Montageauftrag dla klienta — tylko jego projekt i część tygodnia zamknięta dla jego firmy.
 */
class PortalController extends Controller
{
    public function montageauftrag(Request $request, Project $project, WorkWeek $workWeek, PdfRenderer $renderer): Response
    {
        $portal = new ClientPortal($request->user());
        $portal->authorize($project);
        abort_unless($portal->weekIsVisible($workWeek), 404);

        $report = WeeklyReport::query()->where(['work_week_id' => $workWeek->id, 'project_id' => $project->id])->first();
        abort_if($report === null || ! $report->entries()->exists(), 404);

        $document = new Montageauftrag($report);

        return response($renderer->render([$document], __('Weekly report')), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename().'"',
        ]);
    }
}
