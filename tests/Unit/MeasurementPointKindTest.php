<?php

use App\Models\MeasurementCircuit;
use App\Models\MeasurementMarker;
use App\Models\MeasurementPoint;

function pointWith(string $symbol, int $phases = 1, int $position = 1): MeasurementPoint
{
    return (new MeasurementPoint(['symbol' => $symbol, 'position' => $position]))->setRelation('circuit', new MeasurementCircuit(['phases' => $phases]));
}

test('the plan symbol follows the point symbol and the circuit', function (string $symbol, int $phases, string $kind) {
    expect(pointWith($symbol, $phases)->kind())->toBe($kind);
})->with([
    'socket' => ['G3', 1, 'socket'],
    'lighting' => ['O1', 1, 'light'],
    'phase points' => ['L2', 3, 'socket3'],
    'socket on a three-phase circuit' => ['G1', 3, 'socket3'],
    'other point' => ['P1', 1, 'point'],
    'no symbol' => ['', 1, 'point'],
]);

test('a marker takes the symbol of its first point', function () {
    $marker = (new MeasurementMarker)->setRelation('points', collect([pointWith('O1', 1, 2), pointWith('G1', 1, 1)]));

    expect($marker->kind())->toBe('socket')
        ->and((new MeasurementMarker)->setRelation('points', collect())->kind())->toBe('point');
});
