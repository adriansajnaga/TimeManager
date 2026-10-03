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
        ->call('close')
        ->assertHasErrors(['close']);

    expect($this->week->fresh()->isClosed())->toBeFalse();

    WeeklyReport::query()->updateOrCreate(
        ['work_week_id' => $this->week->id, 'project_id' => $this->project->id],
        ['performed_work' => 'Repschalter montiert.'],
    );

    showWeek($this->admin, $this->week)->call('close')->assertHasNoErrors();

    expect($this->week->fresh()->isClosed())->toBeTrue()
        ->and($this->week->fresh()->closed_by)->toBe($this->admin->id);
});

test('only administrators close and reopen weeks', function () {
    showWeek($this->employee, $this->week)->call('close')->assertForbidden();

    $this->week->close($this->admin);

    showWeek($this->employee, $this->week)->call('reopen')->assertForbidden();
    showWeek($this->admin, $this->week)->call('reopen')->assertHasNoErrors();

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
