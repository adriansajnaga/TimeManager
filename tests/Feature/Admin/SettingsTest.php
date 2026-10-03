<?php

use App\Models\BankAccount;
use App\Models\CompanySetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('admin saves company data and the default footer is built from it', function () {
    Livewire::test('pages::admin.company')
        ->set('name', 'ASCOMM Adrian Sajnaga')
        ->set('street', 'ul. Konstytucji 3 Maja 15/12')
        ->set('zip', '87-100')
        ->set('city', 'Toruń')
        ->set('nip', '8792451081')
        ->set('regon', '871123082')
        ->call('save')
        ->assertHasNoErrors();

    $settings = CompanySetting::current();

    expect($settings->exists)->toBeTrue()
        ->and($settings->footer())->toBe('ASCOMM Adrian Sajnaga, NIP: 8792451081, REGON: 871123082');
});

test('NIP and REGON are validated', function () {
    Livewire::test('pages::admin.company')
        ->set('name', 'Firma')
        ->set('street', 'Ulica 1')
        ->set('zip', '00-001')
        ->set('city', 'Warszawa')
        ->set('nip', '879-245-10')
        ->set('regon', '123')
        ->call('save')
        ->assertHasErrors(['nip', 'regon']);
});

test('logo is stored privately and served only to admins', function () {
    Storage::fake('local');

    Livewire::test('pages::admin.company')
        ->set('name', 'ASCOMM Adrian Sajnaga')
        ->set('street', 'ul. Konstytucji 3 Maja 15/12')
        ->set('zip', '87-100')
        ->set('city', 'Toruń')
        ->set('nip', '8792451081')
        ->set('logo', UploadedFile::fake()->image('logo.png', 300, 100))
        ->call('save')
        ->assertHasNoErrors();

    $path = CompanySetting::current()->logo_path;

    expect($path)->toBe('company/logo.png');
    Storage::disk('local')->assertExists($path);

    $this->get(route('admin.company.logo'))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('admin.company.logo'))->assertForbidden();
});

test('only one bank account is the default', function () {
    $first = BankAccount::factory()->create(['is_default' => true]);

    Livewire::test('pages::admin.bank-accounts')
        ->call('create')
        ->set('label', 'REVOLT21')
        ->set('iban', 'lt00 0000 0000 0000 0000')
        ->set('currency', 'eur')
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    $second = BankAccount::query()->where('label', 'REVOLT21')->sole();

    expect($second->iban)->toBe('LT00 0000 0000 0000 0000')
        ->and($second->currency)->toBe('EUR')
        ->and($second->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse()
        ->and(BankAccount::default()->is($second))->toBeTrue();
});

test('vehicles can be added, edited and deleted', function () {
    Livewire::test('pages::admin.vehicles')
        ->call('create')
        ->set('name', 'Ford')
        ->call('save')
        ->assertHasNoErrors();

    $vehicle = Vehicle::sole();
    expect($vehicle->is_default)->toBeTrue();

    Livewire::test('pages::admin.vehicles')
        ->call('edit', $vehicle->id)
        ->set('registration_no', 'ct 12345')
        ->call('save')
        ->call('delete', $vehicle->id);

    expect(Vehicle::count())->toBe(0);
});
