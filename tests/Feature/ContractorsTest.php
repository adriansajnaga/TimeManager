<?php

use App\Enums\PackageDocument;
use App\Enums\VatCode;
use App\Models\ActivityLog;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('admin sees the contractor list', function () {
    $contractor = Contractor::factory()->create(['name' => 'Gärtner Elektrotechnik GmbH']);

    $this->actingAs($this->admin)
        ->get(route('contractors.index'))
        ->assertOk()
        ->assertSee('Gärtner Elektrotechnik GmbH');
});

test('employee cannot open contractors', function () {
    $employee = User::factory()->create();

    $this->actingAs($employee)->get(route('contractors.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('contractors.create'))->assertForbidden();
});

test('admin creates a contractor with billing and document settings', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::contractors.form')
        ->set('form.name', 'Gärtner Elektrotechnik GmbH')
        ->set('form.street', 'Zum Brook 9')
        ->set('form.zip', '24143')
        ->set('form.city', 'Kiel')
        ->set('form.country_code', 'de')
        ->set('form.vat_prefix', 'de')
        ->set('form.tax_id', '286 771 111')
        ->set('form.document_language', 'de')
        ->set('form.invoice_language', 'pl_en')
        ->set('form.currency', 'eur')
        ->set('form.vat_code', VatCode::OutsideScopeEuServices->value)
        ->set('form.hourly_rate', '38')
        ->set('form.km_rate', '0.30')
        ->set('form.base_address', 'Zum Brook 24113 Kiel')
        ->set('form.email_to', '160_rechnungen_gaertner@handwerksgruppe.de')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contractor = Contractor::sole();

    expect($contractor->country_code)->toBe('DE')
        ->and($contractor->vatId())->toBe('DE 286771111')
        ->and($contractor->currency)->toBe('EUR')
        ->and($contractor->hourly_rate)->toBe('38.00')
        ->and($contractor->km_rate)->toBe('0.3000')
        ->and($contractor->vat_code)->toBe(VatCode::OutsideScopeEuServices)
        ->and($contractor->email_to)->toBe(['160_rechnungen_gaertner@handwerksgruppe.de'])
        ->and($contractor->packageDocuments())->toBe([
            PackageDocument::Invoice,
            PackageDocument::Stundenzettel,
            PackageDocument::Mileage,
            PackageDocument::Montageauftrag,
        ]);
});

test('package documents keep the chosen order and selection', function () {
    $this->actingAs($this->admin);
    $contractor = Contractor::factory()->create();

    Livewire::test('pages::contractors.form', ['contractor' => $contractor])
        ->call('moveDocument', PackageDocument::Montageauftrag->value, -1)
        ->set('form.package_included', [
            PackageDocument::Invoice->value,
            PackageDocument::Stundenzettel->value,
            PackageDocument::Montageauftrag->value,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($contractor->fresh()->package_documents)->toBe([
        PackageDocument::Invoice->value,
        PackageDocument::Stundenzettel->value,
        PackageDocument::Montageauftrag->value,
    ]);
});

test('invalid e-mail recipients are rejected', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::contractors.form')
        ->set('form.name', 'Firma')
        ->set('form.email_to', 'ok@example.com, not-an-address')
        ->call('save')
        ->assertHasErrors(['form.email_to']);
});

test('new contractor gets default templates in the document language', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::contractors.form')
        ->set('form.document_language', 'de')
        ->call('applyDefaultTemplates')
        ->assertSet('form.invoice_description_template', "Ausführungszeitraum: {period_from} - {period_to}\n\n{projects}")
        ->assertSet('form.email_subject_template', 'Rechnung {number}');
});

test('changes to a contractor are written to the activity log', function () {
    $this->actingAs($this->admin);
    $contractor = Contractor::factory()->create(['hourly_rate' => '38.00']);

    Livewire::test('pages::contractors.form', ['contractor' => $contractor])
        ->set('form.hourly_rate', '40')
        ->call('save')
        ->assertHasNoErrors();

    $entry = ActivityLog::query()->where('event', 'updated')->latest('id')->first();

    expect($entry->user_id)->toBe($this->admin->id)
        ->and($entry->properties['old']['hourly_rate'])->toBe('38.00')
        ->and($entry->properties['attributes']['hourly_rate'])->toBe('40.00');
});

test('edit page renders for an existing contractor', function () {
    $contractor = Contractor::factory()->create(['name' => 'TKMS GmbH']);

    $this->actingAs($this->admin)
        ->get(route('contractors.edit', $contractor))
        ->assertOk()
        ->assertSee('TKMS GmbH');
});

test('a contractor without projects can be deleted, invoices keep their copy', function () {
    $contractor = Contractor::factory()->create(['name' => 'Do usunięcia']);
    $invoice = Invoice::factory()->for($contractor)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::contractors.form', ['contractor' => $contractor])
        ->call('delete')
        ->assertHasNoErrors()
        ->assertRedirect(route('contractors.index'));

    expect(Contractor::query()->find($contractor->id))->toBeNull()
        ->and($invoice->fresh()->contractor_id)->toBeNull()
        ->and($invoice->fresh()->buyer['name'])->toBe('Do usunięcia');
});

test('a contractor with projects cannot be deleted', function () {
    $contractor = Contractor::factory()->create();
    Project::factory()->for($contractor)->create();

    Livewire::actingAs($this->admin)
        ->test('pages::contractors.form', ['contractor' => $contractor])
        ->call('delete')
        ->assertHasErrors('delete');

    expect($contractor->fresh())->not->toBeNull();
});
