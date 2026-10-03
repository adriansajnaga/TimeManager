<?php

use App\Enums\WorkType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkWeek;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->employee = User::factory()->create();
    $this->project = Project::factory()->create(['mileage_default' => true]);
    $this->project->users()->attach($this->employee);
});

function weekPage(User $user, string $week = '2026-W32')
{
    return Livewire::actingAs($user)->withQueryParams(['week' => $week])->test('pages::time.week');
}

test('employee logs time with start, end and break', function () {
    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->assertSet('form.count_mileage', true)
        ->set('form.start_time', '06:00')
        ->set('form.end_time', '17:00')
        ->set('form.break_minutes', 45)
        ->set('form.work_type', WorkType::Demontage->value)
        ->set('form.description', 'Leitung verlegt')
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::sole();

    expect($entry->user_id)->toBe($this->employee->id)
        ->and($entry->hours)->toBe('10.25')
        ->and($entry->startLabel())->toBe('06:00')
        ->and($entry->work_type)->toBe(WorkType::Demontage)
        ->and($entry->count_mileage)->toBeTrue();
});

test('times must be full quarters and the end must follow start plus break', function () {
    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.start_time', '07:10')
        ->set('form.end_time', '07:30')
        ->set('form.break_minutes', 45)
        ->call('save')
        ->assertHasErrors(['form.start_time' => 'regex']);

    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.start_time', '07:00')
        ->set('form.end_time', '07:30')
        ->set('form.break_minutes', 45)
        ->call('save')
        ->assertHasErrors(['form.end_time']);

    expect(TimeEntry::count())->toBe(0);
});

test('overlapping entries of the same person are rejected', function () {
    TimeEntry::factory()->for($this->employee)->for($this->project)->on('2026-08-03', '07:00', '12:00', 0)->create();

    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.start_time', '11:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasErrors(['form.end_time']);

    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.start_time', '12:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasNoErrors();
});

test('employee cannot log time on a project they are not assigned to', function () {
    $other = Project::factory()->create();

    weekPage($this->employee)
        ->call('create', '2026-08-03', $other->id)
        ->set('form.start_time', '07:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasErrors(['form.project_id']);
});

test('no new entries in a closed week part', function () {
    $admin = User::factory()->admin()->create();
    WorkWeek::forDate(CarbonImmutable::parse('2026-08-03'))->close($admin);

    weekPage($this->employee)
        ->call('create', '2026-08-04', $this->project->id)
        ->set('form.start_time', '07:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasErrors(['form.work_date']);
});

test('entries in a closed week are locked for everybody', function () {
    $admin = User::factory()->admin()->create();
    $entry = TimeEntry::factory()->for($this->employee)->for($this->project)->create();
    $entry->workWeek->close($admin);

    weekPage($this->employee)->call('edit', $entry->id)->assertForbidden();
    weekPage($admin)->call('delete', $entry->id)->assertForbidden();

    $entry->workWeek->reopen();

    weekPage($admin)->call('delete', $entry->id)->assertHasNoErrors();
    expect(TimeEntry::count())->toBe(0);
});

test('employee cannot edit somebody else\'s entry', function () {
    $colleague = User::factory()->create();
    $entry = TimeEntry::factory()->for($colleague)->for($this->project)->create();

    weekPage($this->employee)->call('edit', $entry->id)->assertForbidden();
    weekPage($this->employee)->call('delete', $entry->id)->assertForbidden();
});

test('admin logs time for an employee', function () {
    $admin = User::factory()->admin()->create();

    weekPage($admin)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.user_id', (string) $this->employee->id)
        ->set('form.start_time', '07:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect(TimeEntry::sole()->user_id)->toBe($this->employee->id);
});

test('employee cannot log time for somebody else', function () {
    $colleague = User::factory()->create();
    $this->project->users()->attach($colleague);

    weekPage($this->employee)
        ->call('create', '2026-08-03', $this->project->id)
        ->set('form.user_id', (string) $colleague->id)
        ->set('form.start_time', '07:00')
        ->set('form.end_time', '15:00')
        ->set('form.break_minutes', 0)
        ->call('save')
        ->assertHasErrors(['form.user_id']);
});

test('week view shows the grid with daily and weekly totals', function () {
    TimeEntry::factory()->for($this->employee)->for($this->project)->on('2026-08-03', '06:00', '17:00', 45)->create();
    TimeEntry::factory()->for($this->employee)->for($this->project)->on('2026-08-04', '06:00', '16:15', 45)->create();
    TimeEntry::factory()->for($this->employee)->for($this->project)->on('2026-08-12')->create(); // inny tydzień

    weekPage($this->employee)
        ->assertSee($this->project->number)
        ->assertSee('10.25')
        ->assertSee('9.5')
        ->assertSee('19.75');
});

test('week navigation moves by ISO weeks across the year boundary', function () {
    weekPage($this->employee, '2026-W53')
        ->call('nextWeek')
        ->assertSet('week', '2027-W01')
        ->call('previousWeek')
        ->call('previousWeek')
        ->assertSet('week', '2026-W52');
});
