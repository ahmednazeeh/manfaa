<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo fixture: one merchant with a branch, an active 2% rate, a merchant
 * user, and a customer. Idempotent — safe to re-run. Local/testing only:
 * these credentials are public knowledge and the merchant login could mint
 * real cashback anywhere else (§9.5 keeps fixtures in the sandbox).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $merchant = Merchant::query()->firstOrCreate(
            ['slug' => 'demo-store'],
            [
                'name' => 'Demo Store',
                'status' => 'active',
                'settlement_method' => 'bank',
                'eligibility_basis' => 'Invoice total excluding GST and service charge.',
            ],
        );

        $merchant->branches()->firstOrCreate(
            ['name' => 'Main Branch'],
            ['address' => 'Majeedhee Magu, Malé'],
        );

        $merchant->rates()->firstOrCreate(
            [
                'rate_bp' => 200,
                // 2026-01-01 00:00 Maldives time, converted to UTC for storage.
                'effective_from' => (new CarbonImmutable('2026-01-01T00:00:00+05:00'))->utc(),
            ],
            ['effective_to' => null],
        );

        MerchantUser::query()->firstOrCreate(
            ['email' => 'merchant@demo.manfaa.app'],
            [
                'merchant_id' => $merchant->id,
                'name' => 'Demo Merchant Owner',
                'password' => 'password',
                'role' => 'owner',
            ],
        );

        Customer::query()->firstOrCreate(
            ['phone' => '+9607771234'],
            [
                'customer_code' => Customer::generateCode(),
                'name' => 'Demo Customer',
                'password' => 'password',
                'status' => 'active',
                'phone_verified_at' => now(),
            ],
        );
    }
}
