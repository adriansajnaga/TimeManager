<?php

namespace App\Http\Controllers;

use App\Models\MeasurementProtocol;
use App\Services\Measurements\ReportRenderer;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raport z pomiarów (PDF) — otwierany w przeglądarce.
 */
class MeasurementReportController extends Controller
{
    public function __invoke(MeasurementProtocol $protocol, ReportRenderer $renderer): Response
    {
        $filename = $renderer->filename($protocol);

        return response($renderer->render($protocol), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, Str::ascii($filename)),
        ]);
    }
}
