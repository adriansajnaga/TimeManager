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
    /** Szerokość modułu w mm (skala 1:1). */
    private const MODULE = 18;

    /** Szerokość druku A4 pionowo w mm przy marginesach 12 mm. */
    private const PRINT_WIDTH = 186;

    public function __invoke(MeasurementProtocol $protocol, MeasurementBoard $board): Response
    {
        abort_unless($board->protocol_id === $protocol->id, 404);

        $layout = BoardLayout::for($board);
        $rail = $layout->toArray()['rail'];

        // A4 pionowo: moduł 18 mm (1:1), gdy szyna się mieści; szersza szyna pomniejszona do szerokości strony.
        $module = min(self::MODULE, self::PRINT_WIDTH / max(1, $rail));
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusanscondensed',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 10,
            // Rozmiar szyn liczymy sami (moduł w mm) — mPDF ich nie pomniejsza; szyna, która się nie mieści, przechodzi na następną stronę.
            'shrink_tables_to_fit' => 0,
        ]);
        $mpdf->SetTitle('Rozdzielnica '.$board->name);
        $mpdf->WriteHTML(view('pdf.measurements.styles')->render(), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML(view('pdf.measurements.legend', [
            'protocol' => $protocol,
            'board' => $board,
            'company' => CompanySetting::current(),
            'rail' => $rail,
            'module' => $module,
            'rows' => $layout->resolved(),
        ])->render(), HTMLParserMode::HTML_BODY);

        $filename = 'Rozdzielnica_'.Str::slug($board->name).'.pdf';

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, Str::ascii($filename)),
        ]);
    }
}
