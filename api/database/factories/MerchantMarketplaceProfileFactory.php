<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantMarketplaceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantMarketplaceProfile> */
class MerchantMarketplaceProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'state' => 'active',
            'business_type' => 'pvt_ltd',
            'fulfilment' => 'both',
            'enrolled_at' => now(),
            'approved_at' => now(),
        ];
    }
}
