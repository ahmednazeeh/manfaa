<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'ACC-'.fake()->unique()->numberBetween(1000, 999999),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(['asset', 'liability', 'income', 'expense']),
            'scope' => 'global',
            'owner_id' => null,
        ];
    }
}
