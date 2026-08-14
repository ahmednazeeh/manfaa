<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'status' => 'active',
            'business_reg_no' => 'C-'.fake()->numberBetween(1000, 9999).'/'.fake()->year(),
            'tin' => (string) fake()->numberBetween(1000000000, 1999999999),
            'settlement_method' => 'bank',
            'bank_name' => 'Bank of Maldives',
            'bank_account' => (string) fake()->numberBetween(7700000000000, 7799999999999),
            'validation_window_days' => 3,
            'min_eligible_laari' => 5000,
            'eligibility_basis' => 'Invoice total excluding GST and service charge.',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
