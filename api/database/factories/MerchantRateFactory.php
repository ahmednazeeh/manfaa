<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantRate>
 */
class MerchantRateFactory extends Factory
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
            'rate_bp' => fake()->numberBetween(50, 1000),
            'effective_from' => now()->subMonth(),
            'effective_to' => null,
            'created_by' => null,
        ];
    }
}
