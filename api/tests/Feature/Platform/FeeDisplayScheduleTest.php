<?php

declare(strict_types=1);

use App\Models\ApiCredential;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: every fee DISPLAY surface (merchant panel rate, the V1 till
 * endpoint, the rate-change webhook payload and tier-cliff warning) used the
 * static §4 FeeTier map while billing (TermsResolver) priced from the
 * admin-managed fee_tier_schedules — once a published schedule diverged,
 * merchants and tills were told one fee and charged another.
 */
afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    Queue::fake();

    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    $this->owner = MerchantUser::factory()->owner()->for($this->merchant)->create();

    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);
});

/** Appends a schedule row directly (the admin endpoint refuses past dates). */
function feeDisplaySchedule(CarbonImmutable $effectiveFrom, array $tiers): FeeTierSchedule
{
    return FeeTierSchedule::query()->create([
        'effective_from' => $effectiveFrom->utc(),
        'tiers' => $tiers,
        'created_by' => null,
        'created_at' => CarbonImmutable::now('UTC'),
    ]);
}

it('quotes the published schedule fee, not the static §4 map, on the merchant panel rate', function () {
    // The 200-499 band bills at 100bp under the published schedule (§4
    // static map says 75).
    feeDisplaySchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 30],
        ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 60],
        ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 100],
        ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 150],
    ]);

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 200)
        ->assertJsonPath('data.current.fee_bp', 100)
        ->assertJsonPath('data.current.all_in_bp', 300);
});

it('quotes the schedule fee on the V1 till endpoint, pricing a pending decrease at ITS effective date', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T10:00:00+05:00'));
    $now = CarbonImmutable::now('UTC');
    $boundary = CarbonImmutable::parse('2026-09-11T00:00:00+05:00')->utc();

    // Active now: 200-499 bills at 100bp, 100-199 at 60bp.
    feeDisplaySchedule($now->subDay(), [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 30],
        ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 60],
        ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 100],
        ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 150],
    ]);

    // A later schedule lands BEFORE the pending decrease takes effect: the
    // 100-199 band moves to 70bp. The pending rate must be priced under the
    // schedule in force at its own effective date (70), not the current one
    // (60) and not the static map (50).
    feeDisplaySchedule($now->addHour(), [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 30],
        ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 70],
        ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 100],
        ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 150],
    ]);

    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 150,
        'effective_from' => $boundary,
        'effective_to' => null,
    ]);

    $token = $this->merchant->createToken('till', ['rates:read'])->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/merchants/me/rate')
        ->assertOk()
        ->assertJsonPath('rate_bp', 200)
        ->assertJsonPath('fee_bp', 100)
        ->assertJsonPath('pending_decrease.rate_bp', 150)
        ->assertJsonPath('pending_decrease.fee_bp', 70);

    // The merchant panel prices the pending window identically.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.fee_bp', 100)
        ->assertJsonPath('data.pending.rate_bp', 150)
        ->assertJsonPath('data.pending.fee_bp', 70);
});

it('computes the rate-change webhook payload and tier-cliff warning from the schedule bands in force', function () {
    // Custom bands: one tier boundary at 300 (static §4 has none there).
    // 200 → 300 crosses NO static boundary (75 → 75) but DOES cross the
    // published one (30 → 120) — the cliff warning must fire.
    feeDisplaySchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 299, 'fee_bp' => 30],
        ['from_bp' => 300, 'to_bp' => 1000, 'fee_bp' => 120],
    ]);

    $vendor = PosVendor::query()->create(['name' => 'Vendor '.Str::random(6)]);
    ApiCredential::query()->create([
        'merchant_id' => $this->merchant->id,
        'pos_vendor_id' => $vendor->id,
        'token_hash' => hash('sha256', Str::random(40)),
    ]);
    WebhookEndpoint::query()->create([
        'pos_vendor_id' => $vendor->id,
        'url' => 'https://vendor.example/hooks',
        'secret' => 'whsec_'.Str::random(48),
        'events' => ['merchant.rate_changed'],
        'active' => true,
    ]);

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 300])
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 300)
        ->assertJsonPath('data.current.fee_bp', 120)
        ->assertJsonPath('change.previous.fee_bp', 30)
        ->assertJsonPath('change.previous.all_in_bp', 230)
        ->assertJsonPath('change.new.fee_bp', 120)
        ->assertJsonPath('change.new.all_in_bp', 420)
        ->assertJsonPath('change.tier_changed', true);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->payload['data']['fee_bp'])->toBe(120)
        ->and($delivery->payload['data']['previous_fee_bp'])->toBe(30)
        ->and($delivery->payload['data']['rate_bp'])->toBe(300)
        ->and($delivery->payload['data']['previous_rate_bp'])->toBe(200);
});

it('keeps quoting the seeded §4 defaults when no divergent schedule was ever published', function () {
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 200)
        ->assertJsonPath('data.current.fee_bp', 75)
        ->assertJsonPath('data.current.all_in_bp', 275);
});
