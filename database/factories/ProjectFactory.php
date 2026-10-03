<?php

namespace Database\Factories;

use App\Enums\ProjectBillingType;
use App\Enums\ProjectStatus;
use App\Models\Contractor;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contractor_id' => Contractor::factory(),
            'number' => fake()->unique()->numerify('160######'),
            'name' => fake()->sentence(3),
            'site_name' => fake()->company(),
            'site_street' => fake()->streetAddress(),
            'site_zip' => fake()->postcode(),
            'site_city' => fake()->city(),
            'billing_type' => ProjectBillingType::Hourly,
            'km_one_way' => (string) fake()->numberBetween(5, 120),
            'mileage_default' => false,
            'status' => ProjectStatus::Active,
        ];
    }

    public function fixedPrice(string $value = '10000.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_type' => ProjectBillingType::Fixed,
            'contract_value' => $value,
            'contract_currency' => 'PLN',
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Closed,
        ]);
    }
}
