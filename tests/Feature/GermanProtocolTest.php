<?php

use App\Enums\ProtectionType;
use App\Models\Contractor;
use App\Models\MeasurementProtocol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

function germanProtocol(string $country, int $circuits): MeasurementProtocol
{
    $client = Contractor::factory()->create(['name' => 'Gärtner Elektrotechnik GmbH', 'city' => 'Kiel', 'country_code' => $country]);
    $protocol = MeasurementProtocol::query()->create([...MeasurementProtocol::nextNumber(now()), 'contractor_id' => $client->id, 'place' => 'Halle 14', 'investor' => 'Muster GmbH, Hafenstraße 1, 24143 Kiel', 'measured_on' => now()->toDateString()]);
    $board = $protocol->boards()->create(['position' => 1, 'name' => 'UV1']);

    foreach (range(1, $circuits) as $i) {
        $board->circuits()->create(['position' => $i, 'number' => '-1F'.$i, 'name' => 'Steckdosen '.$i, 'protection_type' => ProtectionType::B, 'protection_current' => 16, 'cable' => 'NYM-J 3x2,5'])
            ->points()->create(['position' => 1, 'symbol' => 'G1', 'loop' => 'L-PE', 'impedance' => '0.6']);
    }

    return $protocol->refresh();
}

function pdfPages(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

test('a client from Germany gets the German form: first page, continuation pages and the floor plan only', function () {
    $protocol = germanProtocol('DE', 8);

    Livewire::actingAs($this->admin)->test('pages::measurements.show', ['protocol' => $protocol])
        ->set('uploads', [UploadedFile::fake()->image('Grundriss.png', 800, 600)]);

    expect($protocol->refresh()->usesGermanTemplate())->toBeTrue();

    // 8 obwodów: 6 na pierwszej stronie, 2 na drugiej, potem rzut — nic więcej.
    $pdf = $this->actingAs($this->admin)->get(route('measurements.report', $protocol))->assertOk()->getContent();
    expect(pdfPages($pdf))->toBe(3);
});

test('a Polish client keeps the Polish protocol', function () {
    $protocol = germanProtocol('PL', 2);

    expect($protocol->usesGermanTemplate())->toBeFalse();
    $this->actingAs($this->admin)->get(route('measurements.report', $protocol))->assertOk();
});

test('the protocol form asks for the German fields only for a client from Germany', function () {
    $german = Contractor::factory()->create(['country_code' => 'DE']);
    $polish = Contractor::factory()->create(['country_code' => 'PL']);

    Livewire::actingAs($this->admin)->test('pages::measurements.form')
        ->set('contractor_id', (string) $polish->id)
        ->assertDontSee('Externe Auftragsnummer')
        ->set('contractor_id', (string) $german->id)
        ->assertSee('Externe Auftragsnummer')
        ->set('place', 'Halle 14')
        ->set('inspection_reason', 'repeat')
        ->set('external_order', 'EXT-4711')
        ->call('save')
        ->assertHasNoErrors();

    $protocol = MeasurementProtocol::query()->latest('id')->first();
    expect($protocol->inspection_reason)->toBe('repeat')->and($protocol->external_order)->toBe('EXT-4711');
});
