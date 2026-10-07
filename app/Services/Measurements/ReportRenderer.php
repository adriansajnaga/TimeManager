<?php

namespace App\Services\Measurements;

use App\Models\Attachment;
use App\Models\CompanySetting;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementContinuity;
use App\Models\MeasurementPerformer;
use App\Models\MeasurementProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * Protokół z pomiarów jako PDF (mPDF): strona tytułowa, wyniki, kryteria, akty prawne i załączniki
 * (rzuty z protokołu, skany uprawnień osób, świadectwo wzorcowania przyrządu — PDF-y dołączane stronami).
 */
final class ReportRenderer
{
    private string $header = '';

    private string $footer = '';

    /** @var list<string> Tymczasowe obrazy rzutów ze znacznikami (usuwane po złożeniu PDF). */
    private array $temporary = [];

    public function __construct(private readonly PlanImage $planImage) {}

    public function render(MeasurementProtocol $protocol): string
    {
        // Świeży odczyt: domyślne wartości kolumn (UL, ta) i aktualne wyniki.
        $protocol->refresh();
        $protocol->syncContinuities();
        $protocol->load([
            'contractor', 'instrument.attachments', 'performers.attachments', 'inspections', 'attachments',
            'boards.rcds', 'boards.circuits.points.marker', 'boards.circuits.rcd', 'markers.board', 'markers.points.circuit', 'earthings', 'continuities.circuit.board.protocol', 'cableTests',
        ]);
        // Ciągłość: wiersze obwodów tylko zmierzone (puste nie trafiają do protokołu), dopisane ręcznie — zawsze.
        $protocol->setRelation('continuities', $protocol->continuities
            ->filter(fn (MeasurementContinuity $row) => $row->circuit_id === null || $row->resistance !== null)
            ->values());

        $data = $this->data($protocol);

        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusanscondensed',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 12,
            'margin_bottom' => 22,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $mpdf->SetTitle($protocol->number);
        $mpdf->SetAuthor((string) CompanySetting::current()->name);
        $mpdf->SetCreator((string) config('app.name'));
        $mpdf->WriteHTML(view('pdf.measurements.styles')->render(), HTMLParserMode::HEADER_CSS);

        $this->footer = view('pdf.measurements.footer')->render();
        $mpdf->SetHTMLFooter($this->footer);

        // Strona tytułowa bez nagłówka protokołu.
        $mpdf->WriteHTML(view('pdf.measurements.title', $data)->render(), HTMLParserMode::HTML_BODY);

        // Kolejne strony: nagłówek z numerem protokołu i stroną; większy margines górny.
        $this->header = view('pdf.measurements.header', $data)->render();
        $mpdf->SetHTMLHeader($this->header);
        $mpdf->AddPageByArray(['margin-top' => 40]);
        $mpdf->WriteHTML(view('pdf.measurements.body', $data)->render(), HTMLParserMode::HTML_BODY);

        $this->appendAttachments($mpdf, $data);

        try {
            return $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            array_map(fn (string $path) => File::delete($path), $this->temporary);
            $this->temporary = [];
        }
    }

    public function filename(MeasurementProtocol $protocol): string
    {
        return 'Protokol_'.str_replace('/', '_', $protocol->number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(MeasurementProtocol $protocol): array
    {
        /** @var Collection<int, MeasurementBoard> $boards */
        $boards = $protocol->boards->where('kind', MeasurementBoard::KIND_BOARD)->values();
        $supply = $protocol->boards->where('kind', MeasurementBoard::KIND_SUPPLY)->values();

        $hasPoints = $boards->contains(fn (MeasurementBoard $board) => $board->circuits->contains(fn (MeasurementCircuit $circuit) => $circuit->points->whereNotNull('impedance')->isNotEmpty()));
        $hasNpe = $boards->contains(fn (MeasurementBoard $board) => $board->circuits->contains(fn (MeasurementCircuit $circuit) => $circuit->points->whereNotNull('impedance_npe')->isNotEmpty()));
        $hasInsulation = $boards->contains(fn (MeasurementBoard $board) => $board->circuits->contains(fn (MeasurementCircuit $circuit) => ! empty($circuit->insulation)));
        $hasRcd = $boards->contains(fn (MeasurementBoard $board) => $board->rcds->isNotEmpty());
        $hasSupply = $supply->contains(fn (MeasurementBoard $board) => $board->circuits->contains(fn (MeasurementCircuit $circuit) => $circuit->points->whereNotNull('impedance')->isNotEmpty()));

        $drawings = $protocol->attachments->values();
        $otherAttachments = $protocol->performers->flatMap(fn (MeasurementPerformer $performer) => $performer->attachments)
            ->merge($protocol->instrument !== null ? $protocol->instrument->attachments : collect())
            ->values();

        $sections = array_values(array_filter([
            'Protokół z oględzin instalacji elektrycznej',
            $hasRcd ? 'Badanie czasu i prądu wyłączników różnicowoprądowych' : null,
            $hasPoints ? 'Badanie ochrony przed porażeniem przez samoczynne wyłączenie' : null,
            $hasInsulation ? 'Badanie stanu izolacji przewodów' : null,
            $protocol->continuities->isNotEmpty() ? 'Badanie ciągłości przewodów ochronnych i połączeń wyrównawczych' : null,
            $protocol->earthings->isNotEmpty() ? 'Badanie rezystancji uziemienia' : null,
            $hasSupply ? 'Badanie impedancji pętli zwarcia WLZ' : null,
            $protocol->cableTests->isNotEmpty() ? 'Badanie rezystancji izolacji kabla zasilającego WLZ' : null,
            'Warunki przeprowadzonych prób i kryteria oceny',
            'Akty prawne i dokumenty normalizacyjne',
            $drawings->isNotEmpty() ? 'Rzuty i rysunki (załączniki)' : null,
            $boards->contains(fn (MeasurementBoard $board) => (bool) ($board->layout['report'] ?? false)) ? 'Rozmieszczenie zabezpieczeń w rozdzielnicach' : null,
            $protocol->performers->contains(fn (MeasurementPerformer $performer) => $performer->attachments->isNotEmpty()) ? 'Kserokopie uprawnień osób przeprowadzających badania' : null,
            ($protocol->instrument?->attachments->isNotEmpty() ?? false) ? 'Świadectwo wzorcowania miernika' : null,
        ]));

        $company = CompanySetting::current();

        return [
            'protocol' => $protocol,
            'company' => $company,
            // Logo firmy z ustawień (jak na fakturach) — na pierwszej stronie protokołu.
            'logo' => $company->logo_path !== null && Storage::disk('local')->exists($company->logo_path)
                ? Storage::disk('local')->path($company->logo_path)
                : null,
            'boards' => $boards,
            'supply' => $supply,
            'hasPoints' => $hasPoints,
            'hasNpe' => $hasNpe,
            'hasInsulation' => $hasInsulation,
            'hasRcd' => $hasRcd,
            'hasSupply' => $hasSupply,
            'hasMarkers' => $protocol->markers->isNotEmpty(),
            'layouts' => $boards->filter(fn (MeasurementBoard $board) => (bool) ($board->layout['report'] ?? false))->map(fn (MeasurementBoard $board) => BoardLayout::for($board))->values(),
            'sections' => $sections,
            'drawings' => $drawings,
            'otherAttachments' => $otherAttachments,
            'instrumentLabel' => $protocol->instrument?->label() ?? '—',
        ];
    }

    /**
     * Załączniki: obrazy na osobnych stronach, PDF-y — strona po stronie (bez nagłówka protokołu).
     *
     * @param  array<string, mixed>  $data
     */
    private function appendAttachments(Mpdf $mpdf, array $data): void
    {
        /** @var Collection<int, Attachment> $drawings */
        $drawings = $data['drawings'];
        /** @var Collection<int, Attachment> $others */
        $others = $data['otherAttachments'];

        foreach ($drawings->values() as $index => $attachment) {
            $this->appendFile($mpdf, $data['protocol'], $attachment, 'ZAŁĄCZNIK '.($index + 1), $attachment->label());
        }

        // Elewacje rozdzielnic (włączone do raportu w edytorze elewacji).
        $number = $drawings->count();

        foreach ($data['layouts'] as $layout) {
            /** @var BoardLayout $layout */
            $array = $layout->toArray();
            $mpdf->AddPageByArray(['margin-top' => 40]);
            $mpdf->WriteHTML(view('pdf.measurements.layout', [
                'title' => 'ZAŁĄCZNIK '.(++$number),
                'board' => $layout->board(),
                'rail' => $array['rail'],
                'rows' => $layout->resolved(),
            ])->render(), HTMLParserMode::HTML_BODY);
        }

        if ($others->isEmpty()) {
            return;
        }

        $mpdf->AddPageByArray(['margin-top' => 40]);
        $mpdf->WriteHTML(view('pdf.measurements.other-attachments', $data)->render(), HTMLParserMode::HTML_BODY);

        foreach ($others as $attachment) {
            $this->appendFile($mpdf, $data['protocol'], $attachment, null, null);
        }
    }

    private function appendFile(Mpdf $mpdf, MeasurementProtocol $protocol, Attachment $attachment, ?string $title, ?string $caption): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->path)) {
            return;
        }

        $path = $disk->path($attachment->path);

        if ($attachment->kind() === 'image') {
            // Rzut ze znacznikami punktów pomiarowych.
            $markers = $protocol->markers->where('attachment_id', $attachment->id);

            if ($markers->isNotEmpty() && ($annotated = $this->planImage->annotate($attachment, $markers)) !== null) {
                $this->temporary[] = $path = $annotated;
            }

            $mpdf->AddPageByArray(['margin-top' => 40]);
            $mpdf->WriteHTML(view('pdf.measurements.image', ['title' => $title, 'caption' => $caption, 'path' => $path])->render(), HTMLParserMode::HTML_BODY);

            return;
        }

        if ($attachment->kind() !== 'pdf') {
            return;
        }

        try {
            $pages = $mpdf->setSourceFile($path);

            // Obce strony bez naszego nagłówka i stopki; potem przywracamy.
            $mpdf->SetHTMLHeader('');
            $mpdf->SetHTMLFooter('');

            for ($page = 1; $page <= $pages; $page++) {
                $template = $mpdf->importPage($page);
                $size = $mpdf->getTemplateSize($template);
                $landscape = is_array($size) && $size['width'] > $size['height'];
                $mpdf->AddPageByArray([
                    'orientation' => $landscape ? 'L' : 'P',
                    'margin-top' => 0, 'margin-bottom' => 0, 'margin-left' => 0, 'margin-right' => 0,
                ]);
                $mpdf->useTemplate($template, 0, 0, $mpdf->w, null, true);
            }
        } catch (Throwable $exception) {
            report($exception);
            $mpdf->AddPageByArray(['margin-top' => 40]);
            $mpdf->WriteHTML('<p class="section">'.e($attachment->label()).'</p><p>Nie udało się dołączyć pliku PDF do raportu — dołącz go osobno.</p>', HTMLParserMode::HTML_BODY);
        } finally {
            $mpdf->SetHTMLHeader($this->header);
            $mpdf->SetHTMLFooter($this->footer);
        }
    }
}
