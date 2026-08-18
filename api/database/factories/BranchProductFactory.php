<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BranchProduct;
use App\Models\MerchantBranch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchProduct> */
class BranchProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => MerchantBranch::factory(),
            'product_id' => Product::factory(),
            'price_laari' => fake()->numberBetween(1000, 50000),
            'stock_qty' => fake()->numberBetween(0, 100),
            'state' => 'active',
        ];
    }
}
