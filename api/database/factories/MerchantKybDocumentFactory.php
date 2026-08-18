<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantKybDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MerchantKybDocument> */
class MerchantKybDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'kind' => 'business_registration',
            'path' => 'kyb/1/'.fake()->uuid().'.jpg',
            'original_name' => 'registration.jpg',
            'mime' => 'image/jpeg',
            'size' => 120000,
            'state' => 'pending',
        ];
    }
}
