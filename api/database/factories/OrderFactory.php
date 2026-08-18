<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'MF-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'customer_id' => Customer::factory(),
            'items_laari' => 10000,
            'delivery_laari' => 2500,
            'total_payable_laari' => 12500,
            'cashback_total_laari' => 200,
            'payment_method' => 'bml',
            'payment_state' => 'awaiting_proof',
            'state' => 'placed',
            'placed_at' => now(),
        ];
    }
}
