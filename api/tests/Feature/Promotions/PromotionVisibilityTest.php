<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\Promotion;
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

    $this->token = $this->merchant->createToken('till', ['rates:read'])->plainTextToken;
});

function promoRate(): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->getJson('/api/v1/merchants/me/rate');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makePromotion(array $overrides = []): Promotion
{
    return Promotion::query()->create([
        'merchant_id' => test()->merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(5),
        'min_purchase_laari' => 100000,
        'status' => 'published',
        'published_at' => now()->subDay(),
        ...$overrides,
    ]);
}

it('omits active_promotion entirely when none is live — the base contract stays byte-stable', function () {
    promoRate()
        ->assertOk()
        ->assertExactJson([
            'rate_bp' => 200,
            'fee_bp' => 75,
            'currency' => 'MVR',
            'min_eligible_laari' => 5000,
            'pending_decrease' => null,
        ]);
});

it('surfaces a live published promotion with its tiered fee, window end and minimum purchase', function () {
    $promotion = makePromotion();

    promoRate()
        ->assertOk()
        ->assertJsonPath('rate_bp', 200)
        ->assertJsonPath('fee_bp', 75)
        ->assertJsonPath('active_promotion.rate_bp', 500)
        ->assertJsonPath('active_promotion.fee_bp', 100)
        ->assertJsonPath('active_promotion.branch_id', null)
        ->assertJsonPath('active_promotion.min_purchase_laari', 100000)
        ->assertJsonPath(
            'active_promotion.ends_at',
            $promotion->ends_at->setTimezone('Indian/Maldives')->toIso8601String(),
        );
});

it('carries the branch scope so a till at another branch can tell the promo is not its own', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();
    makePromotion(['branch_id' => $branch->id]);

    promoRate()
        ->assertOk()
        ->assertJsonPath('active_promotion.rate_bp', 500)
        ->assertJsonPath('active_promotion.branch_id', $branch->id);
});

it('never advertises a draft, a future window, or an ended window', function () {
    makePromotion(['status' => 'draft']);
    makePromotion(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    makePromotion(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

    promoRate()
        ->assertOk()
        ->assertJsonMissingPath('active_promotion');
});

it('never advertises a promo the standing rate has already overtaken — it would not be a boost at the till', function () {
    makePromotion(['rate_bp' => 500]);

    // Standing rises to 500 after publication: the promo no longer boosts.
    MerchantRate::query()->where('merchant_id', $this->merchant->id)->update(['effective_to' => now()->subMinute()]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500,
        'effective_from' => now()->subMinute(),
        'effective_to' => null,
    ]);

    promoRate()
        ->assertOk()
        ->assertJsonPath('rate_bp', 500)
        ->assertJsonMissingPath('active_promotion');
});
