<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => '+9607'.fake()->numerify('######'),
            'building' => 'M. '.fake()->lastName(),
            'island' => 'Malé',
            'lat' => 4.1755,
            'lng' => 73.5093,
            'is_default' => true,
        ];
    }
}
