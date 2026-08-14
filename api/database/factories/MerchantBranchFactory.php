<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantBranch>
 */
class MerchantBranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement(['Malé', 'Hulhumalé', 'Villimalé']).' Branch',
            'address' => fake()->streetAddress(),
            'lat' => fake()->randomFloat(7, 4.1, 4.3),
            'lng' => fake()->randomFloat(7, 73.4, 73.6),
        ];
    }
}
