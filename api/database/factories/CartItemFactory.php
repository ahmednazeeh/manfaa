<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BranchProduct;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'branch_product_id' => BranchProduct::factory(),
            'qty' => 1,
            'unit_price_laari' => 1000,
        ];
    }
}
