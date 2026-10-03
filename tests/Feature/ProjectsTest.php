<?php

use App\Enums\ProjectBillingType;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('admin creates an hourly project with site, mileage and employees', function () {
    $client = Contractor::factory()->german()->create();
    $employee = User::factory()->create();

    Livewire::test('pages::projects.form')
        ->set('form.contractor_id', (string) $client->id)
        ->assertSet('form.site_country', 'DE')
        ->set('form.number', '160226047')
        ->set('form.name', 'Dock1 - Kameraüberwachungsanlage')
        ->set('form.invoice_label', 'Lürssen Krüger Werft Rendsburg')
        ->set('form.site_name', 'Lürssen Kröger Werft')
        ->set('form.site_street', 'Hüttenstraße 25')
        ->set('form.site_zip', '24790')
        ->set('form.site_city', 'Schacht-Audorf')
        ->set('form.km_one_way', '34')
        ->set('form.mileage_default', true)
        ->set('form.user_ids', [(string) $employee->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $project = Project::sole();

    expect($project->fullName())->toBe('160226047 - Dock1 - Kameraüberwachungsanlage')
        ->and($project->invoiceLabel())->toBe('Lürssen Krüger Werft Rendsburg')
        ->and($project->km_one_way)->toBe('34.0')
        ->and($project->mileage_default)->toBeTrue()
        ->and($project->users->pluck('id')->all())->toBe([$employee->id]);
});

test('project number is unique per client but may repeat for another client', function () {
    $existing = Project::factory()->create(['number' => '160245002']);

    Livewire::test('pages::projects.form')
        ->set('form.contractor_id', (string) $existing->contractor_id)
        ->set('form.number', '160245002')
        ->set('form.name', 'Duplikat')
        ->call('save')
        ->assertHasErrors(['form.number' => 'unique']);

    Livewire::test('pages::projects.form')
        ->set('form.contractor_id', (string) Contractor::factory()->create()->id)
        ->set('form.number', '160245002')
        ->set('form.name', 'Inny klient')
        ->call('save')
        ->assertHasNoErrors();
});

test('fixed price project requires a contract value', function () {
    Livewire::test('pages::projects.form')
        ->set('form.contractor_id', (string) Contractor::factory()->create()->id)
        ->set('form.number', '1')
        ->set('form.name', 'Ryczałt')
        ->set('form.billing_type', ProjectBillingType::Fixed->value)
        ->call('save')
        ->assertHasErrors(['form.contract_value' => 'required']);
});

test('invoice label falls back to the project name without any site data', function () {
    $project = Project::factory()->create(['name' => 'TKMS Halle 9', 'invoice_label' => null, 'site_name' => null, 'site_city' => null]);

    expect($project->invoiceLabel())->toBe('TKMS Halle 9');
});

test('editing keeps assigned employees in sync', function () {
    $project = Project::factory()->create();
    [$first, $second] = User::factory()->count(2)->create();
    $project->users()->attach($first);

    Livewire::test('pages::projects.form', ['project' => $project])
        ->assertSet('form.user_ids', [(string) $first->id])
        ->set('form.user_ids', [(string) $second->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($project->users()->pluck('users.id')->all())->toBe([$second->id]);
});

test('project list filters by client and search', function () {
    $kiel = Project::factory()->create(['name' => 'Halle 9', 'site_city' => 'Kiel']);
    $other = Project::factory()->create(['name' => 'Jagel Kantine', 'site_city' => 'Jagel']);

    Livewire::test('pages::projects.index')
        ->set('search', 'Halle')
        ->assertSee('Halle 9')
        ->assertDontSee('Jagel Kantine')
        ->set('search', '')
        ->set('contractor', (string) $other->contractor_id)
        ->assertSee('Jagel Kantine')
        ->assertDontSee('Halle 9');
});

test('the project list shows the newest projects first', function () {
    $older = Project::factory()->create(['number' => '900000001']);
    $newer = Project::factory()->create(['number' => '100000001']);

    Livewire::actingAs($this->admin)
        ->test('pages::projects.index')
        ->assertSeeInOrder([$newer->number, $older->number]);
});

test('a new project copies the site from an earlier project', function () {
    $client = Contractor::factory()->create();
    $tkms = Project::factory()->for($client)->create([
        'invoice_label' => 'TKMS Halle 9',
        'site_name' => 'TKMS GmbH',
        'site_street' => 'Werftstraße 112-114',
        'site_zip' => '24143',
        'site_city' => 'Kiel',
        'site_country' => 'DE',
        'km_one_way' => '6.5',
        'mileage_default' => true,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::projects.form')
        ->set('form.contractor_id', (string) $client->id)
        ->set('form.site_name', 'tkms')
        ->assertSee('Werftstraße 112-114')
        ->call('copySite', $tkms->id)
        ->assertSet('form.site_street', 'Werftstraße 112-114')
        ->assertSet('form.site_zip', '24143')
        ->assertSet('form.site_city', 'Kiel')
        ->assertSet('form.km_one_way', '6.5')
        ->assertSet('form.mileage_default', true)
        ->assertSet('form.invoice_label', 'TKMS Halle 9')
        ->assertDontSee('Earlier projects');
});

test('the invoice shows the place of work when a project has no label', function () {
    $project = Project::factory()->make(['invoice_label' => null, 'site_name' => 'TKMS GmbH', 'site_city' => 'Kiel', 'name' => 'Geb. 112 - Lichtschienen']);

    expect($project->invoiceLabel())->toBe('TKMS GmbH')
        ->and(Project::factory()->make(['invoice_label' => 'TKMS Halle 9'])->invoiceLabel())->toBe('TKMS Halle 9')
        ->and(Project::factory()->make(['invoice_label' => null, 'site_name' => null, 'site_city' => 'Kiel'])->invoiceLabel())->toBe('Kiel');
});
