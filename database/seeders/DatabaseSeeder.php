<?php

namespace Database\Seeders;

use App\Enums\Language;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database (tylko lokalnie — na serwerze seedery nie są uruchamiane).
     *
     * Konta testowe: admin@ascomm.test i pracownik@ascomm.test, hasło: password.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Adrian Sajnaga',
            'email' => 'admin@ascomm.test',
            'role' => Role::Admin,
            'locale' => Language::Polish,
        ]);

        $employee = User::factory()->create([
            'name' => 'Jan Kowalski',
            'email' => 'pracownik@ascomm.test',
            'role' => Role::Employee,
            'locale' => Language::Polish,
        ]);

        $this->call([
            CompanySeeder::class,
            GaertnerSeeder::class,
        ]);

        // Pracownik przypisany do dwóch projektów — do sprawdzania uprawnień.
        $employee->projects()->attach(
            Project::query()->whereIn('number', ['160245002', '160226047'])->pluck('id')
        );

        $admin->projects()->attach(Project::query()->pluck('id'));
    }
}
