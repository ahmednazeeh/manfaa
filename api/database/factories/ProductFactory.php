<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement(['Jasmine Rice 5kg', 'Sunflower Oil 1L', 'Full Cream Milk 1L']),
            'sku' => strtoupper(fake()->bothify('SKU-####??')),
            'allow_substitutions' => true,
            'archived' => false,
        ];
    }
}
