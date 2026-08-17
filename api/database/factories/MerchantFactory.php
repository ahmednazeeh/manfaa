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
            'bank_name' => 'bml',
            'bank_account' => (string) fake()->numberBetween(7700000000000, 7799999999999),
            'validation_window_days' => 3,
            'min_eligible_laari' => 5000,
            'eligibility_basis' => 'Invoice total excluding GST and service charge.',
            'channel' => 'in_store',
            // Every real store signs up with a phone number, and submit now
            // requires one — a phoneless fixture opts out explicitly.
            'contact_phone' => '+960'.fake()->numberBetween(7000000, 7999999),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'submitted_at' => now()->subHour(),
            'rejected_at' => now(),
            'rejected_reason' => 'Please add a clearer eligibility statement.',
        ]);
    }
}
