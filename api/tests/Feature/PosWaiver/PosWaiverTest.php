<?php

declare(strict_types=1);

use App\Domain\Cashback\LineSetParser;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Domain\PosWaiver\PosWaiverEvaluator;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\MerchantRole;
use App\Models\PosVendor;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The POS-fee waiver (owner, 2026-08-23): ≥1% all month AND no overdues
 * AND (MVR 200,000 earning volume OR MVR 5,000 cashback). Only what
 * EARNED counts — excluded categories, below-minimum sales and reversals
 * buy nothing.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'status' => 'active',
        'approved_at' => now()->subMonths(3),
        'min_eligible_laari' => 5000,
        'validation_window_days' => 2,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);

    // "Last month", business time.
    $this->month = CarbonImmutable::now('Indian/Maldives')->subMonthNoOverflow()->startOfMonth();
});

/** A credited sale inside the evaluated month, through the real recorder. */
function saleInMonth(int $eligibleLaari, ?array $lines = null): Transaction
{
    $parsed = $lines === null ? null : app(LineSetParser::class)
        ->parse(test()->merchant, $lines, Laari::of($eligibleLaari));

    $id = app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->owner,
        '482917',
        'W-'.fake()->unique()->numberBetween(1, 999999),
        Laari::of($eligibleLaari),
        null,
        test()->month->addDays(9)->setTime(11, 0),
        $parsed,
    )->id;

    return Transaction::query()->findOrFail($id);
}

it('qualifies on the volume track, counting only what earned', function () {
    // Category with an EXCLUDED bucket: excluded lines earn nothing and
    // must not count toward volume (owner: "doesn't earn for us").
    $this->merchant->productCategories()->create([
        'slug' => 'tobacco', 'name_en' => 'Tobacco', 'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);

    // 10 × 2,020,000 = 20,200,000 earning + 500,000 excluded on one sale.
    foreach (range(1, 10) as $i) {
        saleInMonth(2_020_000);
    }
    saleInMonth(600_000, [
        ['category' => null, 'amount_laari' => 100_000],
        ['category' => 'tobacco', 'amount_laari' => 500_000],
    ]);

    // Below-minimum sale: recorded, zero cashback, counts nothing.
    saleInMonth(4_000);

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->volume_laari)->toBe(10 * 2_020_000 + 100_000)
        ->and($row->qualified)->toBeTrue()
        ->and($row->min_rate_bp)->toBe(200);
});

it('qualifies on the cashback track', function () {
    // 13 × 2,000,000 at 2% = 40,000 each → 520,000 laari cashback (MVR 5,200)
    // while volume (26,000,000) also qualifies — so drop the rate to prove
    // the cashback track alone: use 1% shop, 26 sales of 2,000,000 → cashback
    // 20,000 each = 520,000; volume 52,000,000 — still over. Use SMALL sales:
    // cashback threshold with volume UNDER 20m: 190 sales × 100,000 at 2% =
    // 2,000 each → 380,000… not enough. 260 × 100,000 → 520,000 cashback,
    // volume 26,000,000 — over again. Mathematically volume implies at most
    // rate*volume cashback; 5,000 MVR at 2% needs 250,000 MVR volume — the
    // cashback track only bites for rates >2.5%. Use a 10% shop.
    MerchantRate::query()->where('merchant_id', $this->merchant->id)->update(['rate_bp' => 1000]);

    // 6 × 1,000,000 laari at 10% → 100,000 cashback each = 600,000 laari
    // (MVR 6,000); volume 6,000,000 (MVR 60,000) — under the volume bar.
    foreach (range(1, 6) as $i) {
        saleInMonth(1_000_000);
    }

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->volume_laari)->toBe(6_000_000)
        ->and($row->cashback_laari)->toBe(600_000)
        ->and($row->qualified)->toBeTrue();
});

it('a rate dip below 1% at ANY point of the month disqualifies', function () {
    foreach (range(1, 11) as $i) {
        saleInMonth(2_000_000);
    }

    // A three-day dip to 0.50% mid-month.
    MerchantRate::query()->where('merchant_id', $this->merchant->id)->update([
        'effective_to' => $this->month->addDays(10)->utc(),
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 50,
        'effective_from' => $this->month->addDays(10)->utc(),
        'effective_to' => $this->month->addDays(13)->utc(),
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => $this->month->addDays(13)->utc(),
        'effective_to' => null,
    ]);

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->min_rate_bp)->toBe(50)->and($row->qualified)->toBeFalse();
});

it('reversed sales do not count', function () {
    foreach (range(1, 11) as $i) {
        saleInMonth(2_000_000);
    }
    $volumeBefore = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month)->volume_laari;
    expect($volumeBefore)->toBe(22_000_000);

    // Backdated sales are merchant-irreversible; the evaluator only reads
    // the state, so set it directly — this test is about the SUM, not the
    // reversal machinery (ReversalTest owns that).
    Transaction::query()->latest('id')->firstOrFail()
        ->forceFill(['state' => 'reversed'])->save();

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);
    expect($row->volume_laari)->toBe(20_000_000)->and($row->qualified)->toBeTrue();
});

it('unsettled overdues disqualify whatever the volume', function () {
    foreach (range(1, 11) as $i) {
        saleInMonth(2_000_000);
    }

    // Age one payable past its due date.
    Transaction::query()->latest('id')->first()->forceFill([
        'due_at' => CarbonImmutable::now('UTC')->subDays(3),
    ])->save();

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->overdue_laari)->toBeGreaterThan(0)
        ->and($row->qualified)->toBeFalse();
});

it('the platform reads its merchants\' verdicts with client credentials', function () {
    foreach (range(1, 11) as $i) {
        saleInMonth(2_000_000);
    }

    $secret = 'isle-secret';
    $vendor = PosVendor::query()->create([
        'name' => 'IsleBooks', 'integration_status' => 'active',
        'client_id' => 'mfa_islebooks', 'client_secret_hash' => Hash::make($secret),
        'connect_enabled' => true,
    ]);
    // The merchant holds a live IsleBooks credential.
    $token = $this->merchant->createToken('via IsleBooks', ['transactions:write']);
    ApiCredential::query()->create([
        'merchant_id' => $this->merchant->id,
        'pos_vendor_id' => $vendor->id,
        'personal_access_token_id' => $token->accessToken->getKey(),
        'token_hash' => $token->accessToken->token,
        'abilities' => ['transactions:write'],
    ]);

    $month = $this->month->format('Y-m');

    $this->withHeader('Authorization', 'Basic '.base64_encode('mfa_islebooks:'.$secret))
        ->getJson('/api/v1/connect/waivers?month='.$month)
        ->assertOk()
        ->assertJsonPath('month', $month)
        ->assertJsonPath('data.0.merchant_id', $this->merchant->id)
        ->assertJsonPath('data.0.qualified', true)
        ->assertJsonPath('data.0.volume_laari', 22_000_000);

    // Wrong secret, and a future month, both refuse.
    $this->withHeader('Authorization', 'Basic '.base64_encode('mfa_islebooks:nope'))
        ->getJson('/api/v1/connect/waivers')->assertStatus(401);
    $this->withHeader('Authorization', 'Basic '.base64_encode('mfa_islebooks:'.$secret))
        ->getJson('/api/v1/connect/waivers?month='.CarbonImmutable::now('Indian/Maldives')->format('Y-m'))
        ->assertStatus(422);
});

it('the merchant reads last month and this month\'s progress', function () {
    foreach (range(1, 11) as $i) {
        saleInMonth(2_000_000);
    }

    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/pos-waiver')
        ->assertOk()
        ->assertJsonPath('data.last_month.qualified', true)
        ->assertJsonPath('data.last_month.volume_laari', 22_000_000)
        ->assertJsonPath('data.current_month.rate_ok', true)
        ->assertJsonPath('data.criteria.volume_threshold_laari', 20_000_000);

    app('auth')->forgetGuards();
    $plain = $this->merchant->createToken('shop', ['rates:read'])->plainTextToken;
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/v1/merchants/me/pos-waiver')
        ->assertOk()
        ->assertJsonPath('data.last_month.qualified', true);
});

// ------------------------------------------------------- the good-news push

/** A staff member with a registered device, so a push has somewhere to land. */
function waiverDevice(\App\Models\MerchantUser $user): void
{
    $auth = app(MobileTokenService::class)->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)->getKey(),
        'token' => 'fcm-'.$user->getKey(),
        'platform' => 'android',
    ]);
}

it('pushes the waiver news once to settlement staff, and never twice', function () {
    Queue::fake();

    waiverDevice($this->owner);

    // A cashier cannot open the settlements screen; the waiver push would
    // be news they cannot even verify.
    $cashier = MerchantUser::factory()->for($this->merchant)->withRole(
        MerchantRole::query()->create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Cashier',
            'slug' => 'cashier-'.$this->merchant->id,
            'permissions' => [\App\Domain\MerchantAccess\Permission::CreditsCreate->value],
            'is_owner' => false,
            'is_system' => false,
        ])
    )->create();
    waiverDevice($cashier);

    foreach (range(1, 10) as $i) {
        saleInMonth(2_020_000);
    }

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->qualified)->toBeTrue()
        ->and($row->notified_at)->not->toBeNull();
    Queue::assertPushed(SendPushNotification::class, 1);

    // A re-run replaces the figures but must not repeat the news.
    $again = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($again->notified_at?->toIso8601String())
        ->toBe($row->notified_at->toIso8601String());
    Queue::assertPushed(SendPushNotification::class, 1);
});

it('stays silent about a month that did not qualify', function () {
    Queue::fake();
    waiverDevice($this->owner);

    saleInMonth(2_020_000); // one sale, nowhere near either bar

    $row = app(PosWaiverEvaluator::class)->evaluate($this->merchant, $this->month);

    expect($row->qualified)->toBeFalse()
        ->and($row->notified_at)->toBeNull();
    Queue::assertNothingPushed();
});
