<?php

namespace App\Services\Measurements;

use App\Models\MeasurementBoard;
use App\Models\MeasurementCableTest;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementContinuity;
use App\Models\MeasurementEarthing;
use App\Models\MeasurementInspection;
use App\Models\MeasurementPoint;
use App\Models\MeasurementProtocol;
use App\Models\MeasurementRcd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Protokół na kolejne badanie tego samego obiektu: dane obiektu, rozdzielnice, RCD, obwody, punkty,
 * uziemienia i odcinki — bez wyników (jak „protokół na bazie poprzedniego” w programach producentów).
 */
final class ProtocolCopier
{
    public function copy(MeasurementProtocol $source, User $user): MeasurementProtocol
    {
        return DB::transaction(function () use ($source, $user) {
            $today = CarbonImmutable::today();

            $copy = new MeasurementProtocol([
                ...MeasurementProtocol::nextNumber($today),
                ...$source->only(['contractor_id', 'investor', 'place', 'description', 'instrument_id', 'network', 'phase_voltage', 'line_voltage', 'touch_voltage', 'disconnection_time', 'verdict']),
                'measured_on' => $today,
                'next_test_on' => $today->addYears(MeasurementProtocol::NEXT_TEST_YEARS),
                'created_by' => $user->id,
            ]);
            $copy->save();

            $copy->performers()->sync($source->performers()->pluck('measurement_performers.id'));

            $source->inspections()->get()->each(fn (MeasurementInspection $item) => $copy->inspections()->create(
                [...$item->only(['position', 'section', 'item', 'standard']), 'result' => 'compliant'],
            ));

            foreach ($source->boards()->with(['rcds', 'circuits.points'])->get() as $board) {
                $this->copyBoard($board, $copy);
            }

            $source->earthings()->get()->each(fn (MeasurementEarthing $row) => $copy->earthings()->create($row->only(['position', 'name', 'drawing', 'correction', 'limit'])));
            // Wiersze obwodów powstaną same (syncContinuities) — kopiujemy tylko dopisane ręcznie.
            $source->continuities()->whereNull('circuit_id')->get()->each(fn (MeasurementContinuity $row) => $copy->continuities()->create($row->only(['position', 'name', 'limit'])));
            $source->cableTests()->get()->each(fn (MeasurementCableTest $row) => $copy->cableTests()->create($row->only(['position', 'name', 'cable_type', 'cross_section', 'length', 'test_voltage', 'limit'])));

            return $copy;
        });
    }

    private function copyBoard(MeasurementBoard $board, MeasurementProtocol $copy): void
    {
        $newBoard = $copy->boards()->create($board->only(['position', 'kind', 'name', 'description']));

        $rcdIds = [];

        foreach ($board->rcds as $rcd) {
            /** @var MeasurementRcd $rcd */
            $rcdIds[$rcd->id] = $newBoard->rcds()->create($rcd->only(['position', 'designation', 'model', 'type', 'selective', 'rated_current', 'rated_residual']))->id;
        }

        foreach ($board->circuits as $circuit) {
            /** @var MeasurementCircuit $circuit */
            $newCircuit = $newBoard->circuits()->create([
                ...$circuit->only(['position', 'number', 'name', 'phases', 'protection_type', 'protection_current', 'trip_current_override', 'cable', 'insulation_voltage']),
                'rcd_id' => $circuit->rcd_id !== null ? ($rcdIds[$circuit->rcd_id] ?? null) : null,
            ]);

            foreach ($circuit->points as $point) {
                /** @var MeasurementPoint $point */
                $newCircuit->points()->create($point->only(['position', 'symbol', 'location', 'loop']));
            }
        }
    }
}
