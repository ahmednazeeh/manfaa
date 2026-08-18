<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketplaceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplaceCategory> */
class MarketplaceCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name_en' => fake()->words(2, true),
            'active' => true,
            'sort' => 0,
        ];
    }
}
