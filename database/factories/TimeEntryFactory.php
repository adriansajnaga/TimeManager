<?php

namespace Database\Factories;

use App\Enums\WorkType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state: 07:00–15:45 z przerwą 45 min = 8 h.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'work_date' => '2026-08-03',
            'start_time' => '07:00',
            'end_time' => '15:45',
            'break_minutes' => 45,
            'work_type' => WorkType::Montage,
            'description' => fake()->sentence(),
            'count_mileage' => false,
        ];
    }

    public function on(string $date, string $start = '07:00', string $end = '15:45', int $break = 45): static
    {
        return $this->state(fn (array $attributes) => [
            'work_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => $break,
        ]);
    }
}
