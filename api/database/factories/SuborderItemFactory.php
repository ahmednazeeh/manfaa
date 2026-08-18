<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Suborder;
use App\Models\SuborderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SuborderItem> */
class SuborderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'suborder_id' => Suborder::factory(),
            'name' => 'Jasmine Rice 5kg',
            'unit_price_laari' => 10000,
            'qty' => 1,
            'fulfilled_qty' => 1,
            'line_total_laari' => 10000,
            'cashback_laari' => 200,
        ];
    }
}
