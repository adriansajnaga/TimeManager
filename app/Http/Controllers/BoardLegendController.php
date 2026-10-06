<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\MeasurementBoard;
use App\Models\MeasurementProtocol;
use App\Services\Measurements\BoardLayout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legenda do włożenia do rozdzielnicy: elewacja (w skali 1:1, gdy się mieści) i tabela „który bezpiecznik od czego”.
 */
class BoardLegendController extends Controller
{
    public function __invoke(MeasurementProtocol $protocol, MeasurementBoard $board): Response
    {
        abort_unless($board->protocol_id === $protocol->id, 404);

        $layout = BoardLayout::for($board);
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusanscondensed',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 10,
            // Skala 1:1 — szyn nie pomniejszamy; szyna, która się nie mieści, przechodzi na następną stronę.
            'shrink_tables_to_fit' => 0,
        ]);
        $mpdf->SetTitle('Rozdzielnica '.$board->name);
        $mpdf->WriteHTML(view('pdf.measurements.styles')->render(), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf.measurements.legend', [
            'protocol' => $protocol,
            'board' => $board,
            'company' => CompanySetting::current(),
            'rail' => $layout->toArray()['rail'],
            'rows' => $layout->resolved(),
        ])->render(), HTMLParserMode::HTML_BODY);

        $filename = 'Rozdzielnica_'.Str::slug($board->name).'.pdf';

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, Str::ascii($filename)),
        ]);
    }
}
