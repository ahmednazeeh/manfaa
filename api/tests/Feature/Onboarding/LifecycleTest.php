<?php

declare(strict_types=1);

use App\Domain\Customers\SmsSender;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The whole §1 self-signup journey through real HTTP, nothing seeded by
 * hand: OTP signup → resumable wizard → submit → admin approval → publicly
 * listed → first manual credit works. The single test that proves the
 * pieces meet.
 */
it('takes a store from phone OTP to its first credited sale', function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->seed(LedgerAccountSeeder::class);

    $sms = new class implements SmsSender
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): void
        {
            $this->sent[] = ['phone' => $phone, 'message' => $message];
        }
    };
    $this->app->instance(SmsSender::class, $sms);

    // --- Signup ---------------------------------------------------------
    $this->postJson('/api/merchant/signup/request-otp', ['phone' => '+9607755511'])->assertOk();
    preg_match('/\b(\d{6})\b/', end($sms->sent)['message'], $m);

    $token = $this->postJson('/api/merchant/signup/verify-otp', [
        'phone' => '+9607755511',
        'code' => $m[1],
    ])->assertOk()->json('data.signup_token');

    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Island Bakery',
        'email' => 'owner@islandbakery.mv',
        'password' => 'correct-horse-battery',
    ])->assertCreated();

    // --- Wizard (as the now-logged-in owner) ----------------------------
    $this->patchJson('/api/merchant/setup/profile', [
        'category' => 'cafe',
        'channel' => 'in_store',
        'eligibility_basis' => 'Full bill excluding service charge.',
    ])->assertOk();

    $this->patchJson('/api/merchant/setup/rate', ['cashback_rate_percent' => '2.00'])->assertOk();

    $this->postJson('/api/merchant/setup/submit')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    // Pending: invisible publicly, and crediting is refused.
    $this->getJson('/api/discover/merchants/island-bakery')->assertNotFound();

    $customer = Customer::factory()->create(['customer_code' => '482917']);

    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-0001',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertUnprocessable();

    // --- Admin approval -------------------------------------------------
    $merchant = Merchant::query()->where('slug', 'island-bakery')->firstOrFail();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin');
    $this->postJson("/api/admin/store-reviews/{$merchant->id}/approve")->assertOk();

    // --- Live ------------------------------------------------------------
    $store = $this->getJson('/api/discover/merchants/island-bakery')->assertOk()->json('data');
    expect($store['category'])->toBe('cafe')
        ->and($store['channel'])->toBe('in_store')
        ->and($store['cashback_rate_percent'])->toBe('2.00')
        ->and($store['cashback_basis'])->toBe('Full bill excluding service charge.');

    // The owner's session is still valid — first credit goes through at the
    // wizard rate: ceil(100000 * 200 / 10000) = 2000 laari.
    $owner = MerchantUser::query()->where('email', 'owner@islandbakery.mv')->firstOrFail();
    $this->actingAs($owner, 'merchant');

    // occurred_at must fall after the wizard wrote the initial rate row —
    // the §5 sale-time resolution knows no rate before it.
    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-0001',
        'eligible_amount' => 100000,
        'occurred_at' => now()->toIso8601String(),
    ])->assertCreated()
        ->assertJsonPath('data.cashback_laari', 2000);
});
