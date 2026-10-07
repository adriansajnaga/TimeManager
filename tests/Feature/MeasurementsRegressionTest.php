<?php

use App\Enums\ProtectionType;
use App\Models\MeasurementProtocol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

test('the board page opens after renaming the board and removing markers', function () {
    $protocol = MeasurementProtocol::query()->create([...MeasurementProtocol::nextNumber(now()), 'place' => 'Toruń', 'measured_on' => now()->toDateString()]);
    $protocol->refresh();
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'R1']);
    $supply = $protocol->boards()->create(['position' => 2, 'name' => 'WLZ', 'kind' => 'supply']);
    $circuit = $board->circuits()->create(['position' => 1, 'number' => '1F1', 'name' => 'Salon', 'protection_type' => ProtectionType::B, 'protection_current' => 16]);
    $g1 = $circuit->points()->create(['position' => 1, 'symbol' => 'G1']);
    $g2 = $circuit->points()->create(['position' => 2, 'symbol' => 'G2']);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('uploads', [UploadedFile::fake()->image('rzut.png', 800, 600)]);

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->call('openBoardPlan')->call('placeBoardMarker', 10, 10)
        ->call('openPlan', $g1->id)->call('placeMarker', 30, 30)
        ->call('openPlan', $g2->id)->call('assignMarker', $protocol->markers()->whereNull('board_id')->value('id'))
        ->set('boardName', 'R2')
        ->call('openPlan', $g1->id)->call('unassignPoint')
        ->call('openPlan', $g2->id)->call('unassignPoint');

    $this->actingAs($this->admin)->get(route('measurements.board', [$protocol, $board]))->assertOk()->assertSee('R2');
    $this->get(route('measurements.board', [$protocol, $supply]))->assertOk();
    $this->get(route('measurements.show', $protocol))->assertOk();
    $this->get(route('measurements.report', $protocol))->assertOk();
    $this->get(route('measurements.layout', [$protocol, $board]))->assertOk();
});

test('the board page opens for a circuit with insulation results but no points', function () {
    $protocol = MeasurementProtocol::query()->create([...MeasurementProtocol::nextNumber(now()), 'place' => 'Toruń', 'measured_on' => now()->toDateString()]);
    $protocol->refresh();
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'R2']);
    $board->circuits()->create(['position' => 1, 'number' => '1F1', 'name' => 'Oświetlenie', 'protection_type' => ProtectionType::B, 'protection_current' => 10, 'insulation' => ['L-N' => '>30']]);
    $board->circuits()->create(['position' => 2, 'number' => '1F2', 'name' => 'Brama', 'protection_type' => ProtectionType::B, 'protection_current' => 10, 'insulation' => ['L-N' => '0.2']]);

    $this->actingAs($this->admin)->get(route('measurements.board', [$protocol, $board]))->assertOk()->assertSee('Oświetlenie');
    $this->get(route('measurements.report', $protocol))->assertOk();
});
