<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eligible = fake()->numberBetween(5000, 500000);
        $rateBp = 200;
        $feeBp = 75;

        return [
            'merchant_id' => Merchant::factory(),
            'customer_id' => Customer::factory(),
            'origin' => 'pos',
            'invoice_no' => 'INV-'.fake()->unique()->numberBetween(100000, 999999),
            'eligible_laari' => $eligible,
            'sale_laari' => $eligible,
            'currency' => 'MVR',
            'rate_bp' => $rateBp,
            'fee_bp' => $feeBp,
            'cashback_laari' => intdiv($eligible * $rateBp + 9999, 10000),
            'fee_laari' => intdiv($eligible * $feeBp + 9999, 10000),
            'fee_gst_laari' => 0,
            'state' => 'tracked',
            'occurred_at' => now()->subHour(),
            'received_at' => now(),
        ];
    }
}
