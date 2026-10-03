<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => strtoupper(fake()->lexify('BANK??')),
            'iban' => 'PL'.fake()->numerify('## #### #### #### #### #### ####'),
            'swift' => strtoupper(fake()->lexify('????PLPW')),
            'currency' => 'PLN',
            'is_default' => false,
        ];
    }
}
