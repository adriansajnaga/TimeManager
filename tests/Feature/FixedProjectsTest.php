<?php

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Enums\VatCode;
use App\Models\CompanySetting;
use App\Models\Contractor;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use Database\Seeders\CompanySeeder;
use Database\Seeders\GaertnerSeeder;
use Database\Seeders\ReferencePackageSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    CompanySetting::query()->create(['name' => 'ASCOMM Adrian Sajnaga', 'nip' => '8792451081']);

    $this->client = Contractor::factory()->create(['hourly_rate' => '100.00', 'vat_code' => VatCode::Rate23]);
    $this->project = Project::factory()->for($this->client)->create([
        'number' => 'R-1',
        'name' => 'Instalacja hali',
        'billing_type' => ProjectBillingType::Fixed,
        'contract_value' => '20000.00',
        'status' => ProjectStatus::Active,
    ]);
});

test('the project card shows hours, lump-sum balance and material', function () {
    $entry = TimeEntry::factory()->for($this->admin)->for($this->project)->create(['start_time' => '06:00', 'end_time' => '16:00', 'break_minutes' => 0]);
    $report = WeeklyReport::query()->create(['work_week_id' => $entry->work_week_id, 'project_id' => $this->project->id]);
    $report->materials()->create(['position' => 1, 'name' => 'Kabel YDY 3x2,5', 'quantity' => '50', 'unit' => 'm']);
    $report->materials()->create(['position' => 2, 'name' => 'kabel ydy 3x2,5', 'quantity' => '25', 'unit' => 'm']);

    $this->actingAs($this->admin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertSee('10 h')
        ->assertSee('1 000,00 PLN')
        ->assertSee('20 000,00 PLN')
        ->assertSee('Kabel YDY 3x2,5 — 75 m');
});

test('an advance invoice is prepared from the project', function () {
    Livewire::actingAs($this->admin)
        ->withQueryParams(['kind' => 'zal', 'project' => $this->project->id])
        ->test('pages::invoices.form')
        ->assertSet('form.kind', 'zal')
        ->assertSet('form.contractor_id', (string) $this->client->id)
        ->assertSet('form.project_id', (string) $this->project->id)
        ->assertSet('form.items.0.name', 'R-1 - Instalacja hali')
        ->assertSet('form.items.0.unit_price', '20000.00')
        ->set('form.advance_amount', '6150.00')
        ->call('save')
        ->assertHasNoErrors();

    $advance = Invoice::sole();

    expect($advance->project_id)->toBe($this->project->id)
        ->and($advance->kind)->toBe(InvoiceKind::Advance)
        ->and($advance->net)->toBe('5000.00')
        ->and($advance->vat)->toBe('1150.00');
});

test('the final invoice settles the project advances after completion', function () {
    $advance = Invoice::factory()->for($this->client)->kind(InvoiceKind::Advance)
        ->withItem('20000.00')
        ->create(['project_id' => $this->project->id, 'advance_amount' => '6150.00']);
    $advance->forceFill(['status' => InvoiceStatus::Issued, 'number' => '1/9/2026'])->save();
    $advance->refreshTotals();

    $this->actingAs($this->admin)->get(route('projects.show', $this->project))->assertSee('Close the project first.');

    $this->project->update(['status' => ProjectStatus::Closed]);

    $this->actingAs($this->admin)
        ->get(route('projects.show', $this->project))
        ->assertDontSee('Close the project first.')
        ->assertSee('kind=roz', false);

    Livewire::actingAs($this->admin)
        ->withQueryParams(['kind' => 'roz', 'project' => $this->project->id])
        ->test('pages::invoices.form')
        ->assertSet('form.advance_ids', [(string) $advance->id])
        ->call('save')
        ->assertHasNoErrors();

    $final = Invoice::query()->where('kind', InvoiceKind::Final)->sole();

    expect($final->net)->toBe('15000.00')
        ->and($final->gross)->toBe('18450.00')
        ->and($final->project_id)->toBe($this->project->id);
});

test('a project of another client cannot be attached', function () {
    $other = Project::factory()->create(['billing_type' => ProjectBillingType::Fixed]);

    Livewire::actingAs($this->admin)
        ->test('pages::invoices.form')
        ->set('form.contractor_id', (string) $this->client->id)
        ->set('form.project_id', (string) $other->id)
        ->set('form.items.0.name', 'X')
        ->set('form.items.0.unit_price', '10')
        ->call('save')
        ->assertHasErrors('form.project_id');
});

test('the dashboard shows what is ready to settle and unpaid invoices', function () {
    $this->seed([CompanySeeder::class, GaertnerSeeder::class, ReferencePackageSeeder::class]);
    Invoice::factory()->withItem('100.00')->create(['status' => InvoiceStatus::Issued, 'number' => '9/9/2026', 'due_date' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Gärtner Elektrotechnik GmbH')
        ->assertSee('3 977,80 EUR')
        ->assertSee('Overdue: 1')
        ->assertSee('R-1 - Instalacja hali');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Unpaid invoices')
        ->assertDontSee('Ready to settle');
});

test('the project card lists the working days, newest first', function () {
    $other = User::factory()->create(['name' => 'Anna Nowak']);
    TimeEntry::factory()->for($this->admin)->for($this->project)->create(['work_date' => '2026-08-25', 'start_time' => '06:00', 'end_time' => '07:30', 'break_minutes' => 0, 'description' => 'Kabel verlegt']);
    TimeEntry::factory()->for($this->admin)->for($this->project)->create(['work_date' => '2026-08-26', 'start_time' => '06:00', 'end_time' => '07:30', 'break_minutes' => 0]);
    TimeEntry::factory()->for($other)->for($this->project)->create(['work_date' => '2026-08-26', 'start_time' => '08:00', 'end_time' => '09:00', 'break_minutes' => 0]);

    $this->actingAs($this->admin)
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertSeeInOrder(['26.08.2026', '2,5', 'Anna Nowak', '25.08.2026', '1,5', 'Kabel verlegt'])
        ->assertSee('4 h');
});
