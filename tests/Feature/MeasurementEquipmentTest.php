<?php

use App\Models\MeasurementInstrument;
use App\Models\MeasurementPerformer;
use App\Models\MeasurementProtocol;
use App\Models\User;
use Livewire\Livewire;

test('instruments and people can be added and edited', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('measurements.equipment'))->assertOk();
    $this->get(route('measurements.instrument', ['id' => 'new']))->assertOk();
    $this->get(route('measurements.performer', ['id' => 'new']))->assertOk();

    Livewire::test('pages::measurements.instrument', ['id' => 'new'])
        ->set('name', 'METREL Eurotest AT MI3101')
        ->set('serial_number', '16061617')
        ->set('calibration_valid_until', '2026-02-21')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('pages::measurements.performer', ['id' => 'new'])
        ->set('name', 'Jan Kowalski')
        ->set('certificates', "E1/1\nD1/1")
        ->call('save')
        ->assertHasNoErrors();

    $instrument = MeasurementInstrument::query()->sole();
    $performer = MeasurementPerformer::query()->sole();

    $this->get(route('measurements.instrument', $instrument))->assertOk()->assertSee('16061617');
    $this->get(route('measurements.performer', $performer))->assertOk()->assertSee('Jan Kowalski');
});

test('every measurements page opens over HTTP', function () {
    $admin = User::factory()->admin()->create();
    $protocol = MeasurementProtocol::query()->create([
        ...MeasurementProtocol::nextNumber(now()), 'place' => 'Toruń', 'measured_on' => now()->toDateString(),
    ]);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'R1']);

    $this->actingAs($admin);

    foreach ([
        route('measurements.index'),
        route('measurements.create'),
        route('measurements.show', $protocol),
        route('measurements.edit', $protocol),
        route('measurements.board', [$protocol, $board]),
        route('measurements.report', $protocol),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});
