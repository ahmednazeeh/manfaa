<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    $this->token = $this->merchant->createToken('till', ['rates:read', 'customers:lookup'])->plainTextToken;
});

function v1Get(string $uri, ?string $token = null): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.($token ?? test()->token)])->getJson($uri);
}

it('returns the current rate with tiered fee and no pending decrease', function () {
    v1Get('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertExactJson([
            'cashback_rate_percent' => '2.00',
            'platform_fee_percent' => '0.75',
            'currency' => 'MVR',
            // Always present, unlike active_promotion: it answers a
            // question every till has ("is the headline rate the whole
            // story for this store?"), and a key that comes and goes is
            // exactly what this exact-match assertion exists to prevent.
            'has_category_overrides' => false,
            'min_eligible_laari' => 5000,
            'pending_decrease' => null,
        ]);
});

it('surfaces a scheduled decrease as pending_decrease with its own tiered fee', function () {
    // §9.2: decreases take effect only at 00:00 next day (business tz), so
    // the future-effective history row is the only scheduled change. Stored
    // as the UTC instant (§13: timestamps stored UTC).
    $effective = now('Indian/Maldives')->addDay()->startOfDay()->utc();

    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 150,
        'effective_from' => $effective,
        'effective_to' => null,
    ]);

    v1Get('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('cashback_rate_percent', '2.00')
        ->assertJsonPath('platform_fee_percent', '0.75')
        ->assertJsonPath('pending_decrease.cashback_rate_percent', '1.50')
        ->assertJsonPath('pending_decrease.platform_fee_percent', '0.50')
        ->assertJsonPath('pending_decrease.effective_at', $effective->setTimezone('Indian/Maldives')->toIso8601String());
});

it('does not surface a future increase as pending_decrease', function () {
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 300,
        'effective_from' => now()->addDay(),
        'effective_to' => null,
    ]);

    v1Get('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('pending_decrease', null);
});

it('requires the rates:read ability', function () {
    $lookupOnly = $this->merchant->createToken('lookup', ['customers:lookup'])->plainTextToken;

    v1Get('/api/v1/merchants/me/rate', $lookupOnly)->assertForbidden();
});

it('resolves a customer ref to a masked name for cashier confirmation', function () {
    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed']);

    v1Get('/api/v1/customers/lookup?ref=482917')
        ->assertOk()
        ->assertExactJson([
            'ref' => '482917',
            'valid' => true,
            'masked_name' => 'Ais*** Moh***',
        ]);
});

it('marks a known ref that cannot currently earn as valid:false, still 200', function () {
    Customer::factory()->create(['customer_code' => '104433', 'name' => 'Hussain Adam', 'status' => 'suspended']);

    v1Get('/api/v1/customers/lookup?ref=104433')
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('masked_name', 'Hus*** Ada***');
});

it('answers 404 customer_not_found for an unknown ref', function () {
    v1Get('/api/v1/customers/lookup?ref=000000')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'customer_not_found');
});

it('rejects a malformed ref as 422 validation_failed', function () {
    v1Get('/api/v1/customers/lookup?ref=12')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

it('requires the customers:lookup ability', function () {
    $ratesOnly = $this->merchant->createToken('rates', ['rates:read'])->plainTextToken;

    Customer::factory()->create(['customer_code' => '482917']);

    v1Get('/api/v1/customers/lookup?ref=482917', $ratesOnly)->assertForbidden();
});
