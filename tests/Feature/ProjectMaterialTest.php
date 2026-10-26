<?php

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;

test('the project page sums material even when a quantity is missing or written with a comma', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $entry = TimeEntry::factory()->for($admin)->for($project)->create(['work_date' => '2026-10-05', 'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 0]);
    $report = WeeklyReport::query()->create(['work_week_id' => $entry->work_week_id, 'project_id' => $project->id]);
    $report->materials()->createMany([
        ['position' => 1, 'name' => 'Kabel NYM-J 3x2,5', 'quantity' => '50', 'unit' => 'm'],
        ['position' => 2, 'name' => 'kabel nym-j 3x2,5', 'quantity' => '25.5', 'unit' => 'm'],
        ['position' => 3, 'name' => 'Puszka', 'quantity' => null, 'unit' => 'szt.'],
    ]);

    $this->actingAs($admin)->get(route('projects.show', $project))->assertOk()
        ->assertSee('Kabel NYM-J 3x2,5 — 75.5 m')
        ->assertSee('Puszka');
});
