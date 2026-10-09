<?php

namespace App\Services\Measurements;

use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementInspection;
use App\Models\MeasurementMarker;
use App\Models\MeasurementPoint;
use App\Models\MeasurementProtocol;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Protokół dla klienta z Niemiec: kopia jego formularza „Prüf- und Messprotokoll für elektrische Anlagen”
 * (strona 1 z nagłówkiem, oględzinami i 6 obwodami, dalsze strony — sama tabela obwodów) i rzuty. Nic więcej.
 * Pola „Prüfer” i „Tel. Prüfer” zostają puste (wypełnia klient).
 */
final class GermanReportRenderer
{
    /** Wiersze tabeli obwodów: na pierwszej stronie i na każdej kolejnej (jak w formularzu). */
    public const FIRST_PAGE_ROWS = 6;

    public const NEXT_PAGE_ROWS = 33;

    /** @var list<string> */
    private array $temporary = [];

    public function __construct(private readonly PlanImage $planImage) {}

    public function render(MeasurementProtocol $protocol): string
    {
        $protocol->loadMissing([
            'contractor', 'instrument', 'inspections', 'attachments', 'earthings', 'continuities',
            'boards.rcds', 'boards.circuits.points', 'boards.circuits.rcd', 'markers.board', 'markers.points.circuit',
        ]);
        $contractor = $protocol->contractor;
        $rows = $this->rows($protocol);
        $pages = $this->paginate($rows);

        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'default_font' => 'dejavusanscondensed',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 9,
            'margin_bottom' => 8,
        ]);
        $mpdf->SetTitle('Prüf- und Messprotokoll '.$protocol->number);
        $mpdf->WriteHTML(view('pdf.measurements.de.styles')->render(), HTMLParserMode::HEADER_CSS);

        $common = [
            'protocol' => $protocol,
            'company' => $this->company($contractor),
            'logo' => $contractor?->logo_path !== null && Storage::disk('local')->exists($contractor->logo_path) ? Storage::disk('local')->path($contractor->logo_path) : null,
        ];

        foreach ($pages as $index => $pageRows) {
            if ($index > 0) {
                $mpdf->AddPage();
            }

            $view = $index === 0 ? 'pdf.measurements.de.first-page' : 'pdf.measurements.de.next-page';
            $mpdf->WriteHTML(view($view, [...$common, ...($index === 0 ? $this->firstPage($protocol) : []), 'rows' => $pageRows])->render(), HTMLParserMode::HTML_BODY);
        }

        $this->appendPlans($mpdf, $protocol);

        try {
            return $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            array_map(fn (string $path) => File::delete($path), $this->temporary);
            $this->temporary = [];
        }
    }

    /**
     * Firma z nagłówka formularza — kontrahent (np. Gärtner Elektrotechnik GmbH).
     *
     * @return array{name: string, short: string, address: string, contact: string, hpm: bool}
     */
    private function company(?Contractor $contractor): array
    {
        $name = (string) $contractor?->name;

        return [
            'name' => $name,
            // „Prüfer der Fa. Gärtner Elektrotechnik” — bez formy prawnej.
            'short' => trim((string) preg_replace('/\s+(GmbH(\s*&\s*Co\.?\s*KG)?|AG|KG|UG( \(haftungsbeschränkt\))?|e\.\s?K\.|OHG)$/u', '', $name)),
            'address' => collect([
                $contractor?->street,
                trim($contractor?->zip.' '.$contractor?->city),
                filled($contractor?->phone) ? 'Tel '.$contractor->phone : null,
                filled($contractor?->fax) ? 'Fax '.$contractor->fax : null,
            ])->filter()->implode(' · '),
            'contact' => collect([$contractor?->email, $contractor?->website])->filter()->implode(' · '),
            // Znak grupy HPM jest częścią formularza Gärtner Elektrotechnik.
            'hpm' => str_contains(mb_strtolower($name), 'gärtner'),
        ];
    }

    /**
     * Pola pierwszej strony: zleceniodawca, powód badania, sieć, oględziny, próby, przyrząd, wynik.
     *
     * @return array<string, mixed>
     */
    private function firstPage(MeasurementProtocol $protocol): array
    {
        // „Inwestor” zapisany jako „Nazwa, adres” — rozdzielony na dwa pola formularza.
        [$client, $clientAddress] = array_pad(array_map('trim', explode(',', (string) $protocol->investor, 2)), 2, '');
        $negative = $protocol->inspections->contains(fn (MeasurementInspection $item) => $item->result === 'non_compliant');
        $hasThreePhase = $protocol->boards->contains(fn (MeasurementBoard $board) => $board->circuits->contains(fn (MeasurementCircuit $circuit) => $circuit->phases === 3));
        $hasRcd = $protocol->boards->contains(fn (MeasurementBoard $board) => $board->rcds->contains(fn ($rcd) => $rcd->trip_time !== null || $rcd->trip_current !== null));
        $defects = $this->hasDefects($protocol);
        $instrument = $protocol->instrument;
        [$make, $model] = array_pad(explode(' ', (string) $instrument?->name, 2), 2, '');

        return [
            'client' => $client,
            'clientAddress' => $clientAddress,
            'board' => $protocol->boards->first()?->name,
            'reason' => $protocol->inspection_reason,
            // Nowa instalacja i zmiany — DIN VDE 0100-600; badania okresowe — DIN VDE 0105-100.
            'standard' => in_array($protocol->inspection_reason, ['repeat', 'echeck'], true) ? '0105' : '0100',
            'inspectionOk' => $protocol->inspections->isNotEmpty() && ! $negative,
            'checks' => [
                'rotation' => $hasThreePhase,
                'function' => true,
                'rcd' => $hasRcd,
                'earthing' => $protocol->earthings->contains(fn ($row) => $row->resistance !== null),
                'pe' => $protocol->continuities->contains(fn ($row) => $row->resistance !== null),
            ],
            'instrument' => $instrument === null ? null : [
                'make' => $make,
                'model' => $model,
                'serial' => (string) $instrument->serial_number,
                'until' => $instrument->calibration_valid_until?->format('d.m.Y'),
            ],
            'defects' => $defects,
        ];
    }

    private function hasDefects(MeasurementProtocol $protocol): bool
    {
        foreach ($this->rows($protocol) as $row) {
            if (($row['ok'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wiersze tabeli: obwody kolejnych rozdzielnic; przy zmianie rozdzielnicy wiersz „Verteiler”.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(MeasurementProtocol $protocol): array
    {
        $rows = [];

        foreach ($protocol->boards as $index => $board) {
            if ($index > 0) {
                $rows[] = ['type' => 'board', 'name' => $board->name];
            }

            foreach ($board->circuits as $circuit) {
                $rows[] = $this->circuitRow($protocol, $circuit);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function circuitRow(MeasurementProtocol $protocol, MeasurementCircuit $circuit): array
    {
        $za = $circuit->allowedImpedance($protocol);
        $loops = $circuit->points->reject(fn (MeasurementPoint $point) => $point->isLineToLine())
            ->filter(fn (MeasurementPoint $point) => $point->impedance !== null);
        $worst = $loops->sortByDesc(fn (MeasurementPoint $point) => (float) $point->impedance)->first();
        $rcd = $circuit->rcd;

        // Przewód: „NYM-J 3x2,5” → typ i „3 x 2,5”.
        $cable = trim((string) $circuit->cable);
        $cableType = '';
        $conductors = '';

        if (preg_match('/^(.*?)\s*(\d+)\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*$/u', $cable, $match) === 1) {
            $cableType = trim($match[1]);
            $conductors = $match[2].' x '.str_replace('.', ',', $match[3]);
        } elseif ($cable !== '') {
            $cableType = $cable;
        }

        // Rezystancja izolacji: najmniejszy odczyt (bez odbiorników).
        $readings = array_filter($circuit->insulation ?? [], fn ($reading) => Criteria::insulationValue((string) $reading) !== null);
        uasort($readings, fn ($a, $b) => Criteria::insulationValue((string) $a) <=> Criteria::insulationValue((string) $b));

        $results = $circuit->points->map(fn (MeasurementPoint $point) => $point->passes($za))
            ->push($circuit->insulationPasses())
            ->push($rcd?->passes($protocol->touch_voltage))
            ->filter(fn ($result) => $result !== null);

        return [
            'type' => 'circuit',
            'number' => (string) $circuit->number,
            'place' => $circuit->name,
            'cableType' => $cableType,
            'conductors' => $conductors,
            'protection' => $circuit->protection_type?->label() ?? '',
            'in' => $this->number($circuit->protection_current),
            'zs' => $worst !== null ? $this->number($worst->impedance, 2) : '',
            'ik' => $worst !== null ? $this->number($worst->shortCircuitCurrent($protocol), 0) : '',
            'riso' => $readings === [] ? '' : (string) reset($readings),
            'voltage' => $loops->isNotEmpty() ? (string) $protocol->phase_voltage : '',
            'rcdIn' => $rcd !== null ? $this->number($rcd->rated_current) : '',
            'rcdIdn' => $rcd !== null ? (string) $rcd->rated_residual : '',
            'rcdImess' => $rcd !== null ? $this->number($rcd->trip_current, 1) : '',
            'rcdTime' => $rcd !== null ? $this->number($rcd->trip_time, 1) : '',
            'rcdU' => $rcd !== null ? $this->number($rcd->contact_voltage, 1) : '',
            'ok' => $results->isEmpty() ? null : ! $results->containsStrict(false),
        ];
    }

    /**
     * Strony tabeli: pierwsza — 6 wierszy, kolejne — po 33; puste wiersze dopełniają ostatnią stronę.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>|null>>
     */
    private function paginate(array $rows): array
    {
        $pages = [array_slice($rows, 0, self::FIRST_PAGE_ROWS)];
        $rest = array_slice($rows, self::FIRST_PAGE_ROWS);

        while ($rest !== []) {
            $pages[] = array_slice($rest, 0, self::NEXT_PAGE_ROWS);
            $rest = array_slice($rest, self::NEXT_PAGE_ROWS);
        }

        foreach ($pages as $index => $page) {
            $pages[$index] = array_pad($page, $index === 0 ? self::FIRST_PAGE_ROWS : self::NEXT_PAGE_ROWS, null);
        }

        return $pages;
    }

    /**
     * Rzuty (obrazy) z naniesionymi punktami i legendą po niemiecku.
     */
    private function appendPlans(Mpdf $mpdf, MeasurementProtocol $protocol): void
    {
        $plans = $protocol->attachments->filter(fn (Attachment $attachment) => $attachment->kind() === 'image')->values();

        foreach ($plans as $index => $plan) {
            if (! Storage::disk('local')->exists($plan->path)) {
                continue;
            }

            $markers = $protocol->markers->where('attachment_id', $plan->id);
            $path = Storage::disk('local')->path($plan->path);

            if ($markers->isNotEmpty() && ($annotated = $this->planImage->annotate($plan, $markers, (float) $protocol->marker_size, 'HES')) !== null) {
                $this->temporary[] = $path = $annotated;
            }

            $mpdf->AddPage();
            $mpdf->WriteHTML(view('pdf.measurements.de.plan', [
                'title' => 'Anlage '.($index + 1).' – Grundriss',
                'caption' => $plan->label(),
                'path' => $path,
                'legend' => MeasurementMarker::legendItems($markers),
                'symbols' => collect(array_keys(MeasurementMarker::LEGEND))->mapWithKeys(fn (string $kind) => [
                    $kind => 'data:image/svg+xml;base64,'.base64_encode(Blade::render('<x-plan-symbol :kind="$kind" />', ['kind' => $kind])),
                ])->all(),
                'protocol' => $protocol,
            ])->render(), HTMLParserMode::HTML_BODY);
        }
    }

    private function number(mixed $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = number_format((float) $value, $decimals, ',', '.');

        return $decimals > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
