<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BranchDeliveryRule;
use App\Models\MerchantBranch;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchDeliveryRule> */
class BranchDeliveryRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => MerchantBranch::factory(),
            // A closure, so it is only evaluated when the caller does not
            // supply a zone — Zone has no factory, and creating one per rule
            // would silently multiply islands.
            'zone_id' => fn (): int => Zone::create([
                'name' => 'Test Island '.fake()->unique()->numberBetween(1, 9999),
                'polygon' => [
                    ['lat' => 4.16, 'lng' => 73.49],
                    ['lat' => 4.18, 'lng' => 73.49],
                    ['lat' => 4.18, 'lng' => 73.52],
                    ['lat' => 4.16, 'lng' => 73.52],
                ],
            ])->id,
            'delivery_fee_laari' => 2500,
            'free_delivery_over_laari' => 30000,
            'eta_min' => 30,
            'eta_max' => 60,
        ];
    }
}
