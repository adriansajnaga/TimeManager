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

test('every circuit gets a protective conductor continuity row filled from the board page', function () {
    $protocol = MeasurementProtocol::query()->create([...MeasurementProtocol::nextNumber(now()), 'place' => 'Toruń', 'measured_on' => now()->toDateString()]);
    $protocol->refresh();
    $protocol->continuities()->create(['position' => 1, 'name' => 'Główna szyna wyrównawcza']);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'R1']);
    $salon = $board->circuits()->create(['position' => 1, 'number' => '1F1', 'name' => 'Salon', 'protection_type' => ProtectionType::B, 'protection_current' => 16]);
    $board->circuits()->create(['position' => 2, 'number' => '1F2', 'name' => 'Kuchnia', 'protection_type' => ProtectionType::B, 'protection_current' => 16]);
    $supply = $protocol->boards()->create(['position' => 2, 'name' => 'WLZ', 'kind' => 'supply']);
    $wlz = $supply->circuits()->create(['position' => 1, 'name' => 'Linia zasilająca', 'phases' => 3, 'protection_type' => ProtectionType::GG, 'protection_current' => 35]);

    // gG 35 A na WLZ: Ia z tabeli 5 s.
    expect($wlz->tripCurrent($protocol))->toBe(173.0);

    Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->set("circuits.{$salon->id}.continuity", '0,12')
        ->assertHasNoErrors();

    $rows = $protocol->continuities()->get();
    expect($rows->pluck('name')->all())->toBe(['Główna szyna wyrównawcza', 'R1 · 1F1 Salon', 'R1 · 1F2 Kuchnia', 'WLZ · Linia zasilająca'])
        ->and((float) $rows[1]->resistance)->toBe(0.12)
        // UL / Ia = 50 / 80 A
        ->and(round($rows[1]->limitValue(), 3))->toBe(0.625)
        ->and($rows[1]->passes())->toBeTrue();

    // Wiersz obwodu nie znika ręcznie, a z obwodem — tak.
    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->call('deleteRow', 'continuity', $rows[2]->id)
        ->assertSee('R1 · 1F2 Kuchnia');
    $salon->delete();
    expect($protocol->continuities()->where('name', 'R1 · 1F1 Salon')->exists())->toBeFalse();

    // W PDF tylko zmierzone wiersze obwodów (i ręczne).
    $this->actingAs($this->admin)->get(route('measurements.report', $protocol))->assertOk();
    $protocol->refresh()->syncContinuities();
    expect($protocol->continuities()->count())->toBe(3);
});

test('plan symbols keep the protocol size and can be rotated, also in the report', function () {
    $protocol = MeasurementProtocol::query()->create([...MeasurementProtocol::nextNumber(now()), 'place' => 'Toruń', 'measured_on' => now()->toDateString()]);
    $protocol->refresh();
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'R1']);
    $circuit = $board->circuits()->create(['position' => 1, 'number' => '1F1', 'name' => 'Salon', 'protection_type' => ProtectionType::B, 'protection_current' => 16]);
    $socket = $circuit->points()->create(['position' => 1, 'symbol' => 'G1']);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('uploads', [UploadedFile::fake()->image('rzut.png', 800, 600)]);

    Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->set('markerSize', 9)
        ->call('openPlan', $socket->id)->call('placeMarker', 40, 40)
        ->call('openPlan', $socket->id)->call('rotateMarker')->call('rotateMarker');

    expect((float) $protocol->refresh()->marker_size)->toBe(6.0)
        ->and($socket->refresh()->marker->rotation)->toBe(180)
        ->and($socket->marker->kind())->toBe('socket');

    $this->actingAs($this->admin)->get(route('measurements.report', $protocol))->assertOk();
});
