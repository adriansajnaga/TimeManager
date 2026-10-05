<?php

use App\Models\MaterialEntry;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkWeek;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->employee = User::factory()->create();
    $this->project = Project::factory()->create(['number' => '160245002']);
    $this->project->users()->attach($this->employee);

    $this->entry = TimeEntry::factory()->for($this->employee)->for($this->project)
        ->create(['work_date' => '2026-08-05', 'description' => 'Leitung 5x6 verlegt.']);
    $this->week = $this->entry->workWeek;
});

function showWeek(User $user, WorkWeek $week)
{
    return Livewire::actingAs($user)->test('pages::weeks.show', ['workWeek' => $week]);
}

test('daily descriptions can be appended to the weekly report', function () {
    $report = WeeklyReport::query()->firstOrCreate(['work_week_id' => $this->week->id, 'project_id' => $this->project->id]);

    showWeek($this->employee, $this->week)
        ->set("performed.{$report->id}", 'Linke Hallenseite:')
        ->call('appendDescription', $report->id, $this->entry->id)
        ->assertSet("performed.{$report->id}", 'Linke Hallenseite: Leitung 5x6 verlegt.');
});

test('assigned employee saves performed work, remaining work and materials', function () {
    $component = showWeek($this->employee, $this->week);
    $report = WeeklyReport::query()->where('project_id', $this->project->id)->sole();

    $component
        ->set("performed.{$report->id}", 'Repschalter montiert.')
        ->set("remaining.{$report->id}", 'Beschriftung')
        ->call('addMaterial', $report->id)
        ->set("materials.{$report->id}.0.name", 'NYM-J 5x6')
        ->set("materials.{$report->id}.0.quantity", '25')
        ->set("materials.{$report->id}.0.unit", 'm')
        ->call('saveReport', $report->id)
        ->assertHasNoErrors();

    $report->refresh();

    expect($report->performed_work)->toBe('Repschalter montiert.')
        ->and($report->remaining_work)->toBe('Beschriftung')
        ->and($report->materials->first()->only('name', 'unit'))->toBe(['name' => 'NYM-J 5x6', 'unit' => 'm'])
        ->and($report->materials->first()->quantityLabel())->toBe('25');
});

test('a week cannot be closed until every project is described', function () {
    showWeek($this->admin, $this->week)
        ->call('close', $this->project->contractor_id)
        ->assertHasErrors(['close']);

    expect($this->week->fresh()->isClosed())->toBeFalse();

    WeeklyReport::query()->updateOrCreate(
        ['work_week_id' => $this->week->id, 'project_id' => $this->project->id],
        ['performed_work' => 'Repschalter montiert.'],
    );

    showWeek($this->admin, $this->week)->call('close', $this->project->contractor_id)->assertHasNoErrors();

    expect($this->week->fresh()->isClosed())->toBeTrue()
        ->and($this->week->fresh()->closed_by)->toBe($this->admin->id);
});

test('each client closes separately and only their projects need descriptions', function () {
    $other = Project::factory()->create(['number' => '160226099']);
    TimeEntry::factory()->for($this->employee)->for($other)->create(['work_date' => '2026-08-06', 'start_time' => '06:00', 'end_time' => '10:00', 'break_minutes' => 0]);
    WeeklyReport::query()->updateOrCreate(['work_week_id' => $this->week->id, 'project_id' => $other->id], ['performed_work' => 'Kabel gezogen.']);

    // Projekt pierwszego klienta bez opisu nie blokuje zamknięcia drugiego.
    showWeek($this->admin, $this->week)
        ->assertSee($other->contractor->name)
        ->assertSee($this->project->contractor->name)
        ->call('close', $other->contractor_id)
        ->assertHasNoErrors()
        ->assertSee(__(':closed of :total clients closed', ['closed' => 1, 'total' => 2]));

    $this->week->refresh();
    expect($this->week->isClosedFor($other->contractor_id))->toBeTrue()
        ->and($this->week->isClosedFor($this->project->contractor_id))->toBeFalse()
        ->and($this->week->isClosed())->toBeFalse();

    // Raport zamkniętego klienta tylko do odczytu, drugiego — dalej edytowalny.
    $closedReport = WeeklyReport::query()->where('project_id', $other->id)->sole();
    $openReport = WeeklyReport::query()->where('project_id', $this->project->id)->sole();
    expect($this->admin->can('update', $closedReport))->toBeFalse()
        ->and($this->admin->can('update', $openReport))->toBeTrue();

    showWeek($this->admin, $this->week)->call('reopen', $other->contractor_id)->assertHasNoErrors();
    expect($this->week->fresh()->isClosedFor($other->contractor_id))->toBeFalse();
});

test('only administrators close and reopen weeks', function () {
    showWeek($this->employee, $this->week)->call('close', $this->project->contractor_id)->assertForbidden();

    $this->week->close($this->admin);

    showWeek($this->employee, $this->week)->call('reopen', $this->project->contractor_id)->assertForbidden();
    showWeek($this->admin, $this->week)->call('reopen', $this->project->contractor_id)->assertHasNoErrors();

    expect($this->week->fresh()->isClosed())->toBeFalse();
});

test('reports of a closed week are read-only', function () {
    $report = WeeklyReport::query()->firstOrCreate(['work_week_id' => $this->week->id, 'project_id' => $this->project->id]);
    $this->week->close($this->admin);

    showWeek($this->admin, $this->week)
        ->set("performed.{$report->id}", 'Nachträglich')
        ->call('saveReport', $report->id)
        ->assertForbidden();
});

test('employee sees only projects they are assigned to', function () {
    $other = Project::factory()->create(['name' => 'Fremdes Projekt']);
    TimeEntry::factory()->for($this->admin)->for($other)->create(['work_date' => '2026-08-06', 'start_time' => '16:00', 'end_time' => '18:00', 'break_minutes' => 0]);

    showWeek($this->employee, $this->week)->assertDontSee('Fremdes Projekt');
    showWeek($this->admin, $this->week)->assertSee('Fremdes Projekt');
});

test('week list shows parts with hours and description progress', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::weeks.index')
        ->assertSee('KW 32/2026 (03.08–09.08)')
        ->assertSee('0/1');
});

test('material rows require a description', function () {
    $component = showWeek($this->employee, $this->week);
    $report = WeeklyReport::query()->where('project_id', $this->project->id)->sole();

    $component->call('addMaterial', $report->id)
        ->call('saveReport', $report->id)
        ->assertHasErrors(["materials.{$report->id}.0.name"]);

    expect(MaterialEntry::count())->toBe(0);
});
