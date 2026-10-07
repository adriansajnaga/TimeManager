<?php

use App\Enums\Role;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->worker = User::factory()->create(['name' => 'Jan Monter']);
    $this->gaertner = Contractor::factory()->create(['name' => 'Gärtner Elektrotechnik GmbH']);
    $this->other = Contractor::factory()->create(['name' => 'Inna Firma']);
    $this->project = Project::factory()->for($this->gaertner)->create(['number' => '160245002', 'name' => 'Halle 14']);
    $this->foreign = Project::factory()->for($this->other)->create(['number' => '999000001', 'name' => 'Cudzy projekt']);

    // Zamknięta część tygodnia (zatwierdzone godziny) i otwarta.
    $approved = TimeEntry::factory()->for($this->worker)->for($this->project)->create(['work_date' => '2026-08-05', 'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 0]);
    WeeklyReport::query()->create(['work_week_id' => $approved->work_week_id, 'project_id' => $this->project->id, 'performed_work' => 'Kabel verlegt.']);
    $approved->workWeek->closeFor($this->gaertner, $this->admin);
    $this->closedWeek = $approved->workWeek;

    $draft = TimeEntry::factory()->for($this->worker)->for($this->project)->create(['work_date' => '2026-09-14', 'start_time' => '06:00', 'end_time' => '09:00', 'break_minutes' => 0, 'description' => 'Trasy kablowe']);
    WeeklyReport::query()->create(['work_week_id' => $draft->work_week_id, 'project_id' => $this->project->id, 'performed_work' => 'Entwurf, noch offen.']);
    $this->openWeek = $draft->workWeek;

    $this->client = User::factory()->create(['role' => Role::Client, 'contractor_id' => $this->gaertner->id]);
});

test('the client sees all hours of their own projects, without amounts', function () {
    $this->actingAs($this->client)->get(route('dashboard'))->assertRedirect(route('portal.index'));

    $this->get(route('portal.index'))->assertOk()
        ->assertSee('160245002')->assertSee('Halle 14')->assertSee('8')
        ->assertDontSee('999000001');

    $this->get(route('portal.project', $this->project))->assertOk()
        ->assertSee('Jan Monter')
        ->assertSee('14.09.2026')->assertSee(__('In progress'))
        ->assertDontSee('Entwurf, noch offen.');

    // Przebieg tygodnia: projekty × dni, z niezatwierdzonymi godzinami.
    $this->get(route('portal.week', ['week' => '2026-W38']))->assertOk()
        ->assertSee('160245002')->assertSee('14.09')->assertSee(__('In progress'))->assertSee($this->admin->name)
        ->assertDontSee('999000001');

    $this->get(route('portal.project', $this->foreign))->assertNotFound();

    $this->get(route('portal.montageauftrag', [$this->project, $this->closedWeek]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('portal.montageauftrag', [$this->project, $this->openWeek]))->assertNotFound();
    $this->get(route('portal.montageauftrag', [$this->foreign, $this->closedWeek]))->assertNotFound();
});

test('the client cannot open anything else', function () {
    $this->actingAs($this->client);

    foreach (['invoices.index', 'projects.index', 'contractors.index', 'time.week', 'weeks.index', 'settlements.index', 'measurements.index', 'notes.index'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    // Administrator i pracownik nie mają widoku klienta.
    $this->actingAs($this->admin)->get(route('portal.index'))->assertForbidden();
    $this->actingAs($this->worker)->get(route('portal.index'))->assertForbidden();
});

test('a client account needs a company', function () {
    Livewire::actingAs($this->admin)->test('pages::admin.users.form')
        ->set('form.name', 'Herr Gärtner')
        ->set('form.email', 'gaertner@example.test')
        ->set('form.role', 'client')
        ->set('form.locale', 'de')
        ->set('form.password', 'Sehr-geheim-2026!')
        ->set('form.password_confirmation', 'Sehr-geheim-2026!')
        ->call('save')
        ->assertHasErrors(['form.contractor_id'])
        ->set('form.contractor_id', (string) $this->gaertner->id)
        ->call('save')
        ->assertHasNoErrors();

    $client = User::query()->where('email', 'gaertner@example.test')->sole();
    expect($client->isClient())->toBeTrue()->and($client->contractor_id)->toBe($this->gaertner->id);
});
