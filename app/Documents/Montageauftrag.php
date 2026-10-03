<?php

namespace App\Documents;

use App\Enums\Language;
use App\Models\MaterialEntry;
use App\Models\WeeklyReport;

/**
 * Montageauftrag — jeden na projekt na część tygodnia w miesiącu (układ jak w starej aplikacji).
 */
final class Montageauftrag extends WorkDocument
{
    /** Minimalna liczba wierszy w każdej z dwóch tabel materiałów. */
    private const MATERIAL_ROWS = 5;

    /** Minimalna liczba wierszy tabeli godzin (osoby + puste wiersze do ręcznego uzupełnienia). */
    private const HOUR_ROWS = 3;

    public function __construct(private readonly WeeklyReport $report)
    {
        $this->report->loadMissing('project.contractor', 'workWeek', 'materials');
    }

    public function view(): string
    {
        return 'pdf.montageauftrag';
    }

    public function language(): Language
    {
        return $this->report->project->contractor->document_language;
    }

    public function filename(): string
    {
        $week = $this->report->workWeek;

        return sprintf('Montageauftrag_KW%02d-%d_%02d_%s.pdf', $week->iso_week, $week->iso_year, $week->month, $this->report->project->number);
    }

    public function data(): array
    {
        $common = $this->common();
        $client = $this->report->project->contractor;
        $hours = WeekHours::perUser($this->report->entries()->with('user')->get());

        return [
            ...$common,
            'report' => $this->report,
            'project' => $this->report->project,
            'client' => $client,
            'clientTaxLine' => self::taxLine($client, $common['t']),
            'week' => $this->report->workWeek,
            'days' => $this->report->workWeek->weekDays(),
            'hours' => $hours,
            'emptyHourRows' => max(0, self::HOUR_ROWS - count($hours)),
            'materialColumns' => $this->materialColumns(),
            'reportDate' => $this->report->reportDate(),
        ];
    }

    /**
     * Materiały w dwóch tabelach obok siebie (1–5 i 6–10, przy większej liczbie wiersze rosną).
     *
     * @return array{0: list<array{no: int, material: MaterialEntry|null}>, 1: list<array{no: int, material: MaterialEntry|null}>}
     */
    private function materialColumns(): array
    {
        $materials = $this->report->materials->values();
        $rows = max(self::MATERIAL_ROWS, (int) ceil($materials->count() / 2));
        $columns = [[], []];

        for ($column = 0; $column < 2; $column++) {
            for ($row = 0; $row < $rows; $row++) {
                $index = $column * $rows + $row;
                $columns[$column][] = [
                    'no' => $index + 1,
                    'material' => $materials->get($index),
                ];
            }
        }

        return $columns;
    }
}
