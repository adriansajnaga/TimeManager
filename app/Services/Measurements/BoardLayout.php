<?php

namespace App\Services\Measurements;

use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementRcd;
use Illuminate\Support\Collection;

/**
 * Elewacja rozdzielnicy: szyny DIN z modułami w kolejności od lewej.
 * Moduł: rcd / circuit (odwołanie do pomiarów), device (F0, WG, SPD… z opisem) albo gap (puste miejsce);
 * w — szerokość w modułach (obwód 1F = 1, 3F = 3; RCD 2 albo 4).
 *
 * Struktura w measurement_boards.layout: {rail, report, rows: [[{t, id?, w, label?, desc?}, …], …]}.
 *
 * @phpstan-type Item array{t: string, id: int|null, w: int, label: string|null, desc: string|null}
 * @phpstan-type Layout array{rail: int, report: bool, rows: list<list<Item>>}
 */
final class BoardLayout
{
    public const RAILS = [12, 18, 24];

    /** Szybkie dodawanie typowych aparatów. */
    public const DEVICES = [
        'F0' => 'Bezpiecznik główny',
        'WG' => 'Wyłącznik główny',
        'SPD' => 'Ogranicznik przepięć',
        'K' => 'Lampki kontrolne',
    ];

    /** @var Collection<int, MeasurementRcd> */
    private Collection $rcds;

    /** @var Collection<int, MeasurementCircuit> */
    private Collection $circuits;

    private int $rail;

    private bool $report;

    /** @var list<list<Item>> */
    private array $rows;

    /**
     * @param  array<mixed>  $layout  dane z bazy (normalizowane tutaj)
     */
    private function __construct(private readonly MeasurementBoard $board, array $layout)
    {
        $this->rcds = $board->rcds()->get()->keyBy('id');
        $this->circuits = $board->circuits()->get()->keyBy('id');

        $rail = (int) ($layout['rail'] ?? 12);
        $this->rail = in_array($rail, self::RAILS, true) ? $rail : 12;
        $this->report = (bool) ($layout['report'] ?? false);
        $this->rows = self::normalizeRows($layout['rows'] ?? []);
    }

    /** Zapisany układ uzupełniony o nowe RCD/obwody i bez usuniętych — albo ułożony automatycznie. */
    public static function for(MeasurementBoard $board): self
    {
        if ($board->layout === null) {
            return self::auto($board, report: false);
        }

        $layout = new self($board, $board->layout);
        $layout->sync();

        return $layout;
    }

    /**
     * Każdy RCD na osobnej szynie ze swoimi obwodami; obwody bez RCD na końcu.
     */
    public static function auto(MeasurementBoard $board, bool $report = true): self
    {
        $layout = new self($board, ['rail' => $board->layout['rail'] ?? 12, 'report' => $report, 'rows' => []]);
        $rows = [];

        foreach ($layout->rcds as $rcd) {
            $circuits = $layout->circuits->where('rcd_id', $rcd->id);
            $row = [self::item('rcd', $rcd->id, $circuits->contains(fn (MeasurementCircuit $circuit) => $circuit->phases === 3) ? 4 : 2)];

            foreach ($circuits as $circuit) {
                $row[] = self::circuitItem($circuit);
            }

            $rows[] = $row;
        }

        $rest = [];

        foreach ($layout->circuits as $circuit) {
            if ($circuit->rcd_id === null || ! $layout->rcds->has($circuit->rcd_id)) {
                $rest[] = self::circuitItem($circuit);
            }
        }

        if ($rest !== []) {
            $rows[] = $rest;
        }

        $layout->rows = $rows === [] ? [[]] : $rows;

        return $layout;
    }

    /**
     * @return Item
     */
    private static function item(string $type, ?int $id, int $width, ?string $label = null, ?string $description = null): array
    {
        return ['t' => $type, 'id' => $id, 'w' => max(1, min(8, $width)), 'label' => $label, 'desc' => $description];
    }

    /**
     * @return Item
     */
    private static function circuitItem(MeasurementCircuit $circuit): array
    {
        return self::item('circuit', $circuit->id, $circuit->phases === 3 ? 3 : 1);
    }

    /**
     * @return list<list<Item>>
     */
    private static function normalizeRows(mixed $rows): array
    {
        $result = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $items = [];

            foreach (is_array($row) ? $row : [] as $item) {
                if (! is_array($item) || ! in_array($item['t'] ?? null, ['rcd', 'circuit', 'device', 'gap'], true)) {
                    continue;
                }

                $items[] = self::item(
                    (string) $item['t'],
                    isset($item['id']) ? (int) $item['id'] : null,
                    (int) ($item['w'] ?? 1),
                    isset($item['label']) ? (string) $item['label'] : null,
                    isset($item['desc']) ? (string) $item['desc'] : null,
                );
            }

            $result[] = $items;
        }

        return $result === [] ? [[]] : $result;
    }

    /** Dokłada nowe RCD i obwody, usuwa te, których już nie ma. */
    private function sync(): void
    {
        $placed = ['rcd' => [], 'circuit' => []];
        $rows = [];

        foreach ($this->rows as $row) {
            $kept = [];

            foreach ($row as $item) {
                if (in_array($item['t'], ['rcd', 'circuit'], true)) {
                    $placed[$item['t']][] = (int) $item['id'];
                    $exists = $item['t'] === 'rcd' ? $this->rcds->has((int) $item['id']) : $this->circuits->has((int) $item['id']);

                    if (! $exists) {
                        continue;
                    }
                }

                $kept[] = $item;
            }

            $rows[] = $kept;
        }

        foreach ($this->rcds as $rcd) {
            if (! in_array($rcd->id, $placed['rcd'], true)) {
                $rows[] = [self::item('rcd', $rcd->id, 2)];
            }
        }

        foreach ($this->circuits as $circuit) {
            if (in_array($circuit->id, $placed['circuit'], true)) {
                continue;
            }

            // Na szynę swojego RCD, inaczej na ostatnią.
            $target = max(0, count($rows) - 1);

            foreach ($rows as $index => $row) {
                foreach ($row as $item) {
                    if ($circuit->rcd_id !== null && $item['t'] === 'rcd' && $item['id'] === $circuit->rcd_id) {
                        $target = $index;
                    }
                }
            }

            $rows[$target] ??= [];
            $rows[$target][] = self::circuitItem($circuit);
        }

        $this->rows = $rows === [] ? [[]] : $rows;
    }

    /**
     * @return Layout
     */
    public function toArray(): array
    {
        return ['rail' => $this->rail, 'report' => $this->report, 'rows' => $this->rows];
    }

    /**
     * Szyny z opisami do wyświetlenia: etykieta, zabezpieczenie, opis (pionowy nad modułem), szerokość, rodzaj.
     *
     * @return list<list<array{t: string, label: string, sub: string, desc: string, w: int}>>
     */
    public function resolved(): array
    {
        return array_map(fn (array $row) => array_map(fn (array $item) => [
            't' => $item['t'],
            'label' => $this->label($item),
            'sub' => $this->subLabel($item),
            'desc' => $this->description($item),
            'w' => $item['w'],
        ], $row), $this->rows);
    }

    /**
     * @param  Item  $item
     */
    private function label(array $item): string
    {
        return match ($item['t']) {
            'rcd' => (string) $this->rcds->get((int) $item['id'])?->designation,
            'circuit' => (string) $this->circuits->get((int) $item['id'])?->number,
            'device' => (string) $item['label'],
            default => '',
        };
    }

    /**
     * Zabezpieczenie pod numerem: „B16”, „A 30 mA”.
     *
     * @param  Item  $item
     */
    private function subLabel(array $item): string
    {
        if ($item['t'] === 'circuit') {
            return (string) $this->circuits->get((int) $item['id'])?->protectionLabel();
        }

        if ($item['t'] === 'rcd' && ($rcd = $this->rcds->get((int) $item['id'])) !== null) {
            return $rcd->type->value.' '.$rcd->rated_residual.' mA';
        }

        return '';
    }

    /**
     * @param  Item  $item
     */
    private function description(array $item): string
    {
        if ($item['t'] === 'rcd') {
            $numbers = $this->circuits->where('rcd_id', (int) $item['id'])->pluck('number')->filter()->values();

            return $numbers->isEmpty() ? 'Wyłącznik różnicowoprądowy' : 'Różnicówka zabezpiecza obwody '.($numbers->count() > 2 ? $numbers->first().'–'.$numbers->last() : $numbers->implode(', '));
        }

        return match ($item['t']) {
            'circuit' => mb_strtoupper((string) $this->circuits->get((int) $item['id'])?->name),
            'device' => (string) $item['desc'],
            default => '',
        };
    }

    public function board(): MeasurementBoard
    {
        return $this->board;
    }
}
