<?php

use App\Documents\InvoicePdf;
use App\Documents\MileageAllowance;
use App\Documents\Montageauftrag;
use App\Documents\SettlementPackage;
use App\Documents\Stundenzettel;
use App\Enums\InvoiceLineMode;
use App\Enums\VatCode;
use App\Models\Contractor;
use App\Models\MileageDay;
use App\Models\Project;
use App\Models\Settlement;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use App\Services\Ksef\Fa3InvoiceBuilder;
use App\Services\Ksef\Fa3Validator;
use App\Services\Mileage\MileageCalculator;
use App\Services\Settlements\SettlementService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\GaertnerSeeder;
use Database\Seeders\ReferencePackageSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Adrian Sajnaga']);

    $this->seed([CompanySeeder::class, GaertnerSeeder::class, ReferencePackageSeeder::class]);

    $this->gaertner = Contractor::query()->where('name', 'Gärtner Elektrotechnik GmbH')->sole();
    $this->weeks = WorkWeek::query()->orderBy('starts_on')->get();
});

test('mileage: one project doubles the distance, several projects use the entered kilometres', function () {
    $trips = collect(app(MileageCalculator::class)->trips($this->weeks, $this->gaertner))
        ->mapWithKeys(fn ($trip) => [$trip->date->toDateString() => [$trip->kmLabel(), $trip->route]]);

    expect($trips->all())->toBe([
        '2026-07-27' => ['165', 'Zum Brook 24113 Kiel -> Jagel -> Marinestützpunkt Eckernförde -> Hohn -> Zum Brook 24113 Kiel'],
        '2026-07-30' => ['181', 'Zum Brook 24113 Kiel -> Hamburg -> Zum Brook 24113 Kiel'],
        '2026-07-31' => ['112', 'Zum Brook 24113 Kiel -> Jagel -> Zum Brook 24113 Kiel'],
        '2026-08-03' => ['181', 'Zum Brook 24113 Kiel -> Hamburg -> Zum Brook 24113 Kiel'],
        '2026-08-04' => ['112', 'Zum Brook 24113 Kiel -> Jagel -> Zum Brook 24113 Kiel'],
    ]);

    MileageDay::query()->delete();

    $missing = collect(app(MileageCalculator::class)->trips($this->weeks, $this->gaertner))->filter->needsKm();
    expect($missing->map(fn ($trip) => $trip->date->toDateString())->values()->all())->toBe(['2026-07-27']);
});

test('the reference package 4/8/2026 settles to 3 977,80 €', function () {
    $preview = app(SettlementService::class)->preview($this->gaertner, $this->weeks);

    expect((string) $preview->hours)->toBe('98.75')
        ->and((string) $preview->hoursAmount())->toBe('3752.50')
        ->and((string) $preview->km)->toBe('751.0')
        ->and((string) $preview->kmAmount())->toBe('225.30')
        ->and((string) $preview->total())->toBe('3977.80')
        ->and($preview->problems())->toBe([]);

    Livewire::actingAs($this->admin)
        ->test('pages::settlements.index', ['contractorId' => (string) $this->gaertner->id])
        ->assertSee('3 977,80 EUR')
        ->call('createInvoice')
        ->assertHasNoErrors()
        ->assertRedirect();

    $settlement = Settlement::sole();
    $invoice = $settlement->invoice;

    expect($invoice->gross)->toBe('3977.80')
        ->and($invoice->currency)->toBe('EUR')
        ->and($invoice->sale_date->toDateString())->toBe('2026-08-06')
        ->and($invoice->items->sole()->vat_code)->toBe(VatCode::ReverseCharge)
        ->and($invoice->items->sole()->name)->toBe("Ausführungszeitraum: 27.07.2026 - 06.08.2026\n\n- BW Jagel, Kropp, Hohn\n- H-TEC Hamburg\n- MADEC\n- TKMS Halle 9\n- WTD71 ECK")
        ->and($settlement->workWeeks)->toHaveCount(3)
        ->and(WorkWeek::query()->whereNull('invoiced_at')->count())->toBe(0)
        ->and(app(SettlementService::class)->billableWeeks($this->gaertner))->toBeEmpty();

    // Jak faktura 5/8/2026 z Aplikacji Podatnika: „oo” w P_13_10 i adnotacja P_18.
    $invoice->forceFill(['number' => '4/8/2026'])->save();
    $xml = app(Fa3InvoiceBuilder::class)->build($invoice->fresh());

    expect(app(Fa3Validator::class)->errors($xml))->toBe([])
        ->and($xml)->toContain('<P_13_10>3977.8</P_13_10>')
        ->toContain('<P_18>1</P_18>')
        ->toContain('<P_12>oo</P_12>');

    $documents = app(SettlementPackage::class)->documents($invoice->fresh());

    expect(array_map(fn ($document) => $document::class, array_slice($documents, 0, 3)))->toBe([InvoicePdf::class, Stundenzettel::class, MileageAllowance::class])
        ->and(collect($documents)->filter(fn ($document) => $document instanceof Montageauftrag))->toHaveCount(11);

    $this->actingAs($this->admin)->get(route('invoices.package', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('the mileage document shows the weeks, routes and allowance', function () {
    $document = new MileageAllowance($this->gaertner, $this->weeks, $this->admin);
    $html = view($document->view(), $document->data())->render();

    expect($html)->toContain('Kilometergeldabrechnung')
        ->toContain('Auftraggeber: Gärtner Elektrotechnik GmbH')
        ->toContain('ASCOMM Adrian Sajnaga')
        ->toContain('KW 31-32/2026')
        ->toContain('Marinestützpunkt Eckernförde')
        ->toContain('>751<')
        ->toContain('225,30');

    $this->actingAs($this->admin)
        ->get(route('documents.mileage', ['client' => $this->gaertner->id, 'weeks' => $this->weeks->pluck('id')->all()]))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('documents.reports', ['client' => $this->gaertner->id, 'weeks' => $this->weeks->pluck('id')->all()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('deleting the draft invoice releases the weeks', function () {
    $settlement = app(SettlementService::class)->createInvoice($this->gaertner, $this->weeks, $this->admin);

    Livewire::actingAs($this->admin)->test('pages::invoices.show', ['invoice' => $settlement->invoice])->call('delete');

    expect(Settlement::count())->toBe(0)
        ->and(WorkWeek::query()->whereNotNull('invoiced_at')->count())->toBe(0)
        ->and(app(SettlementService::class)->billableWeeks($this->gaertner))->toHaveCount(3);
});

test('a settled week cannot be reopened', function () {
    app(SettlementService::class)->createInvoice($this->gaertner, $this->weeks, $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::weeks.show', ['workWeek' => $this->weeks->first()])
        ->call('reopen', $this->gaertner->id)
        ->assertHasErrors('close');

    expect($this->weeks->first()->fresh()->isClosed())->toBeTrue();
});

test('itemized invoices get separate hour and mileage lines', function () {
    $this->gaertner->update(['invoice_line_mode' => InvoiceLineMode::Itemized]);

    $invoice = app(SettlementService::class)->createInvoice($this->gaertner, $this->weeks, $this->admin)->invoice;

    expect($invoice->items->map(fn ($item) => [$item->unit, $item->quantityLabel(), $item->unit_price])->all())
        ->toBe([['h', '98.75', '38.00'], ['km', '751', '0.30']])
        ->and($invoice->gross)->toBe('3977.80');
});

test('a week shared with another client stays billable for that client', function () {
    $other = Contractor::factory()->create(['hourly_rate' => '50.00']);
    $project = Project::factory()->for($other)->create();
    TimeEntry::factory()->for($this->admin)->for($project)->create(['work_date' => '2026-08-07', 'start_time' => '06:00', 'end_time' => '10:00', 'break_minutes' => 0]);

    app(SettlementService::class)->createInvoice($this->gaertner, $this->weeks, $this->admin);

    $shared = WorkWeek::forDate(now()->setDate(2026, 8, 7));

    // Godziny drugiego klienta dopisane do części zamkniętej dla Gärtnera — u niego trzeba ją zamknąć osobno.
    expect($shared->fresh()->invoiced_at)->toBeNull()
        ->and($shared->fresh()->isClosed())->toBeFalse()
        ->and(app(SettlementService::class)->billableWeeks($other))->toBeEmpty();

    $shared->closeFor($other, $this->admin);

    expect($shared->fresh()->isClosed())->toBeTrue()
        ->and(app(SettlementService::class)->billableWeeks($other)->pluck('id')->all())->toBe([$shared->id]);
});

test('closing a week needs kilometres for days with several projects', function () {
    $week = WorkWeek::forDate(now()->setDate(2026, 7, 27));
    $week->reopen();
    MileageDay::query()->delete();

    $page = Livewire::actingAs($this->admin)->test('pages::weeks.show', ['workWeek' => $week])
        ->call('close', $this->gaertner->id)
        ->assertHasErrors('close');

    expect($week->fresh()->isClosed())->toBeFalse();

    $key = $this->admin->id.'-'.$this->gaertner->id.'-2026-07-27';

    $page->set("mileageKm.{$key}", '165')
        ->call('saveMileage', $key)
        ->assertHasNoErrors()
        ->call('close', $this->gaertner->id)
        ->assertHasNoErrors();

    expect($week->fresh()->isClosed())->toBeTrue()
        ->and(MileageDay::sole()->km)->toBe('165.0')
        ->and(MileageDay::sole()->route)->toBeNull();
});

test('only users who manage settlements open settlements', function () {
    $this->actingAs(User::factory()->create())->get(route('settlements.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('settlements.index'))->assertOk()->assertSee('Gärtner Elektrotechnik GmbH');
});

test('the package file is named after the invoice number', function () {
    $invoice = app(SettlementService::class)->createInvoice($this->gaertner, $this->weeks, $this->admin)->invoice;
    $invoice->forceFill(['number' => '4/8/2026'])->save();

    expect(app(SettlementPackage::class)->filename($invoice))->toBe('2026_8_4 ASCOMM-zusammengefügt.pdf');

    $this->actingAs($this->admin)
        ->get(route('invoices.package', $invoice))
        ->assertOk()
        ->assertHeader('Content-Disposition', "inline; filename=\"2026_8_4 ASCOMM-zusammengefugt.pdf\"; filename*=utf-8''2026_8_4%20ASCOMM-zusammengef%C3%BCgt.pdf");
});
