<?php

use App\Enums\ProtectionType;
use App\Models\MeasurementBoard;
use App\Models\MeasurementCircuit;
use App\Models\MeasurementInstrument;
use App\Models\MeasurementPerformer;
use App\Models\MeasurementPoint;
use App\Models\MeasurementProtocol;
use App\Models\User;
use App\Services\Measurements\BoardLayout;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
    $this->instrument = MeasurementInstrument::query()->create(['name' => 'METREL Eurotest AT MI3101', 'serial_number' => '16061617']);
    $this->performer = MeasurementPerformer::query()->create(['name' => 'Jan Kowalski', 'certificates' => "Świadectwo kwalifikacyjne E1/1\nŚwiadectwo kwalifikacyjne D1/1"]);
});

function newProtocol(User $user): MeasurementProtocol
{
    Livewire::actingAs($user)->test('pages::measurements.form')
        ->set('place', 'Kijaszkowo dz. nr 79/49')
        ->set('investor', 'Inwestor Testowy')
        ->set('description', 'Instalacja elektryczna w nowym budynku mieszkalnym')
        ->set('measured_on', '2025-02-15')
        ->call('save')
        ->assertHasNoErrors();

    return MeasurementProtocol::query()->latest('id')->firstOrFail();
}

test('employees cannot open measurements', function () {
    $this->actingAs(User::factory()->create())->get(route('measurements.index'))->assertForbidden();
});

test('a new protocol gets the next number of its month, defaults and the inspection list', function () {
    $protocol = newProtocol($this->admin);

    expect($protocol->number)->toBe('PROT/1/2/2025')
        ->and($protocol->instrument_id)->toBe($this->instrument->id)
        ->and($protocol->performers()->pluck('name')->all())->toBe(['Jan Kowalski'])
        ->and($protocol->next_test_on->toDateString())->toBe('2030-02-15')
        ->and($protocol->inspections()->count())->toBe(count(MeasurementProtocol::INSPECTION_TEMPLATE));

    expect(newProtocol($this->admin)->number)->toBe('PROT/2/2/2025');

    $this->actingAs($this->admin)->get(route('measurements.index'))->assertOk()->assertSee('PROT/1/2/2025');
    $this->get(route('measurements.show', $protocol))->assertOk()->assertSee('Kijaszkowo');
});

test('results are entered on the board page and evaluated right away', function () {
    $protocol = newProtocol($this->admin);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('newBoard', 'UV1')
        ->call('addBoard', 'board');

    $board = $protocol->boards()->sole();
    expect($board->name)->toBe('UV1');

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->call('addRcd')
        ->call('addCircuit');

    $rcd = $board->rcds()->sole();
    $circuit = $board->circuits()->sole();

    $page->set("rcds.{$rcd->id}.trip_time", '18,3')
        ->set("rcds.{$rcd->id}.trip_current", '24')
        ->set("circuits.{$circuit->id}.number", '2F4')
        ->set("circuits.{$circuit->id}.name", 'Pokój gościnny')
        ->set("circuits.{$circuit->id}.rcd_id", (string) $rcd->id)
        ->call('addPoints', $circuit->id, 'socket')
        ->call('addPoints', $circuit->id, 'socket')
        ->call('fillInsulation', $circuit->id, '>30');

    $points = $circuit->points()->get();
    expect($points->pluck('symbol')->all())->toBe(['G1', 'G2'])
        ->and($points->first()->location)->toBe('Pokój gościnny - '.__('socket'));

    $page->set("points.{$points[0]->id}.impedance", '1,36')
        ->set("points.{$points[1]->id}.impedance", '3,2')
        ->assertSee('169');

    $circuit->refresh()->load('points');
    $za = $circuit->allowedImpedance($protocol);

    expect($circuit->protection_type)->toBe(ProtectionType::B)
        ->and((float) $circuit->protection_current)->toBe(16.0)
        ->and(round((float) $za, 3))->toBe(2.875)
        ->and($circuit->points[0]->passes($za))->toBeTrue()
        ->and($circuit->points[1]->passes($za))->toBeFalse()
        ->and($circuit->insulation)->toBe(['L-N' => '>30', 'L-PE' => '>30', 'N-PE' => '>30'])
        ->and($circuit->insulationPasses())->toBeTrue()
        ->and($rcd->refresh()->passes($protocol->touch_voltage))->toBeTrue()
        ->and($circuit->rcd_id)->toBe($rcd->id);

    // Kolejny obwód przejmuje zabezpieczenie i RCD, numer rośnie.
    $page->call('addCircuit');
    $next = $board->circuits()->reorder()->latest('position')->first();
    expect($next->number)->toBe('2F5')->and($next->rcd_id)->toBe($rcd->id);
});

test('the supply line gets its segments and L-L loops have no short-circuit current', function () {
    $protocol = newProtocol($this->admin);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])->call('addBoard', 'supply');

    $board = $protocol->boards()->sole();
    $circuit = $board->circuits()->sole();

    expect($board->kind)->toBe(MeasurementBoard::KIND_SUPPLY)
        ->and($circuit->points()->pluck('symbol')->all())->toBe(['L1-N', 'L2-N', 'L3-N', 'L1-PE', 'L2-PE', 'L3-PE', 'L1-L2', 'L2-L3', 'L3-L1']);

    $circuit->update(['protection_type' => ProtectionType::C, 'protection_current' => 25]);
    $lineToLine = $circuit->points()->where('symbol', 'L1-L2')->sole();
    $lineToLine->update(['impedance' => '0.74']);

    expect($lineToLine->shortCircuitCurrent($protocol))->toBeNull()
        ->and($lineToLine->passes(0.92))->toBeTrue();
});

test('the report PDF contains the results, and a copy keeps the structure without results', function () {
    $protocol = newProtocol($this->admin);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'UV1']);
    $rcd = $board->rcds()->create(['position' => 1, 'designation' => 'Fi1', 'type' => 'A', 'rated_residual' => 30, 'trip_time' => '18.3', 'trip_current' => '24']);
    $circuit = $board->circuits()->create(['position' => 1, 'number' => '2F4', 'name' => 'Pokój gościnny', 'protection_type' => ProtectionType::B, 'protection_current' => 16, 'rcd_id' => $rcd->id, 'insulation' => ['L-N' => '>30']]);
    $circuit->points()->create(['position' => 1, 'symbol' => 'G1', 'location' => 'Pokój gościnny - gniazdo', 'impedance' => '1.36', 'impedance_npe' => '0.83']);
    $protocol->earthings()->create(['position' => 1, 'name' => 'Uziom fundamentowy', 'resistance' => '5.5', 'correction' => '1.3', 'limit' => '10']);
    $protocol->cableTests()->create(['position' => 1, 'name' => 'WLZ', 'cable_type' => 'YKY', 'cross_section' => '5x16', 'values' => ['L1-L2' => '>1000']]);

    // Rzut jako załącznik protokołu.
    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('uploads', [UploadedFile::fake()->image('rzut.png', 400, 300)])
        ->assertHasNoErrors();

    $response = $this->actingAs($this->admin)->get(route('measurements.report', $protocol));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->call('copyForNextTest');

    $copy = MeasurementProtocol::query()->latest('id')->firstOrFail();
    $copiedCircuit = MeasurementCircuit::query()->whereIn('board_id', $copy->boards()->select('id'))->sole();

    expect($copy->id)->not->toBe($protocol->id)
        ->and($copy->place)->toBe($protocol->place)
        ->and($copiedCircuit->number)->toBe('2F4')
        ->and($copiedCircuit->insulation)->toBeNull()
        ->and($copiedCircuit->rcd_id)->not->toBe($rcd->id)
        ->and($copiedCircuit->rcd_id)->not->toBeNull()
        ->and(MeasurementPoint::query()->where('circuit_id', $copiedCircuit->id)->sole()->impedance)->toBeNull()
        ->and($copy->earthings()->sole()->resistance)->toBeNull();
});

test('points are marked on the plan and sockets next to each other share one number', function () {
    $protocol = newProtocol($this->admin);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'UV1']);
    $circuit = $board->circuits()->create(['position' => 1, 'number' => '2F4', 'name' => 'Salon', 'protection_type' => ProtectionType::B, 'protection_current' => 16]);
    $g1 = $circuit->points()->create(['position' => 1, 'symbol' => 'G1', 'location' => 'Salon - gniazdo', 'impedance' => '1.2']);
    $g2 = $circuit->points()->create(['position' => 2, 'symbol' => 'G2', 'location' => 'Salon - gniazdo', 'impedance' => '1.3']);
    $o1 = $circuit->points()->create(['position' => 3, 'symbol' => 'O1', 'location' => 'Salon - oświetlenie']);

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board]);

    // Bez rzutu — podpowiedź, żeby go dodać.
    $page->call('openPlan', $g1->id)->assertSet('planPoint', null);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('uploads', [UploadedFile::fake()->image('rzut.png', 800, 600)]);
    $plan = $protocol->attachments()->sole();

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->call('openPlan', $g1->id)
        ->assertSet('planId', $plan->id)
        ->call('placeMarker', 25.5, 40.0);

    $marker = $protocol->markers()->sole();
    expect($marker->number)->toBe(1)->and((float) $marker->x)->toBe(25.5)->and($g1->fresh()->marker_id)->toBe($marker->id);

    // G2 obok G1 — ten sam numer; O1 — nowy numer.
    $page->call('openPlan', $g2->id)->call('assignMarker', $marker->id)
        ->call('openPlan', $o1->id)->call('placeMarker', 70, 20);

    expect($g2->fresh()->marker_id)->toBe($marker->id)
        ->and($o1->fresh()->marker->number)->toBe(2)
        ->and($marker->points()->count())->toBe(2);

    $page->call('moveMarker', $marker->id, 30, 45);
    expect((float) $marker->fresh()->x)->toBe(30.0);

    // Raport z rzutem ze znacznikami i kolumną „Rzut”.
    $this->get(route('measurements.report', $protocol))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(glob(storage_path('app/mpdf/plan-*.png')))->toBe([]);

    // Odpięcie O1 usuwa pusty znacznik 2; usunięcie G1 i G2 — znacznik 1.
    $page->call('openPlan', $o1->id)->call('unassignPoint');
    expect($protocol->markers()->pluck('number')->all())->toBe([1]);

    $page->call('deletePoint', $g1->id)->call('deletePoint', $g2->id);
    expect($protocol->markers()->count())->toBe(0);
});

test('the board elevation is arranged from RCDs and circuits and can be edited', function () {
    $protocol = newProtocol($this->admin);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'UV1']);
    $fi1 = $board->rcds()->create(['position' => 1, 'designation' => 'Fi1', 'type' => 'A']);
    $fi2 = $board->rcds()->create(['position' => 2, 'designation' => 'Fi2', 'type' => 'A']);
    $pump = $board->circuits()->create(['position' => 1, 'number' => '1F1', 'name' => 'Pompa ciepła', 'phases' => 3, 'rcd_id' => $fi1->id]);
    $board->circuits()->create(['position' => 2, 'number' => '2F1', 'name' => 'Salon', 'rcd_id' => $fi2->id]);
    $board->circuits()->create(['position' => 3, 'number' => '2F2', 'name' => 'Sypialnia', 'rcd_id' => $fi2->id]);

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.layout', ['protocol' => $protocol, 'board' => $board]);

    $rows = BoardLayout::for($board->fresh())->resolved();
    expect(array_column($rows[0], 'label'))->toBe(['Fi1', '1F1'])
        ->and(array_column($rows[0], 'w'))->toBe([4, 3])
        ->and(array_column($rows[1], 'label'))->toBe(['Fi2', '2F1', '2F2'])
        ->and($rows[1][0]['desc'])->toBe('Różnicówka zabezpiecza obwody 2F1, 2F2')
        ->and($rows[1][1]['desc'])->toBe('SALON')
        ->and($board->fresh()->layout['report'])->toBeTrue();

    // F0 i WG na nowej szynie, przesunięcie obwodu, nowy obwód dochodzi sam na szynę swojego RCD.
    $page->call('addRow')->call('addItem', 'device', 'F0')->call('addItem', 'device', 'WG')
        ->call('select', 1, 2)->call('shift', -1);

    $board->circuits()->create(['position' => 4, 'number' => '2F3', 'name' => 'Hubert', 'rcd_id' => $fi2->id]);
    $pump->delete();

    $rows = BoardLayout::for($board->fresh())->resolved();
    expect(array_column($rows[0], 'label'))->toBe(['Fi1'])
        ->and(array_column($rows[1], 'label'))->toBe(['Fi2', '2F2', '2F1', '2F3'])
        ->and(array_column($rows[2], 'label'))->toBe(['F0', 'WG'])
        ->and($rows[2][0]['desc'])->toBe('Bezpiecznik główny');

    $this->actingAs($this->admin)->get(route('measurements.layout', [$protocol, $board]))->assertOk()->assertSee('Fi2');
    $this->get(route('measurements.legend', [$protocol, $board]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('measurements.report', $protocol))->assertOk();
});

test('Ia is filled in from the protection and a different value is kept as manual', function () {
    $protocol = newProtocol($this->admin);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'UV1']);

    $page = Livewire::actingAs($this->admin)->test('pages::measurements.board', ['protocol' => $protocol, 'board' => $board])
        ->call('addCircuit');
    $circuit = $board->circuits()->sole();

    // Domyślnie B16 → 80 A.
    $page->assertSet("circuits.{$circuit->id}.trip_current_override", '80')
        ->set("circuits.{$circuit->id}.protection_type", 'C')
        ->assertSet("circuits.{$circuit->id}.trip_current_override", '160')
        ->set("circuits.{$circuit->id}.protection_current", '20')
        ->assertSet("circuits.{$circuit->id}.trip_current_override", '200');

    expect($circuit->fresh()->trip_current_override)->toBeNull();

    // Wartość z katalogu — zapamiętana; wyczyszczenie wraca do wyliczonej.
    $page->set("circuits.{$circuit->id}.trip_current_override", '150');
    expect((float) $circuit->fresh()->trip_current_override)->toBe(150.0);

    $page->set("circuits.{$circuit->id}.trip_current_override", '')
        ->assertSet("circuits.{$circuit->id}.trip_current_override", '200');
    expect($circuit->fresh()->trip_current_override)->toBeNull();
});
