<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\Order;
use App\Models\Suborder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Suborder> */
class SuborderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'merchant_id' => Merchant::factory(),
            'branch_id' => MerchantBranch::factory(),
            'reference' => 'MF-'.fake()->unique()->numberBetween(1000, 9999).'-01',
            'fulfilment' => 'delivery',
            'items_laari' => 10000,
            'delivery_laari' => 2500,
            'subtotal_laari' => 12500,
            'cashback_rate_bp' => 200,
            'cashback_laari' => 200,
            'order_fee_bp' => 200,
            'order_fee_laari' => 200,
            'order_fee_gst_laari' => 0,
            'payable_to_merchant_laari' => 12100,
            'state' => 'new',
        ];
    }
}
