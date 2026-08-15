<?php

declare(strict_types=1);

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\SendWebhook;
use App\Models\ApiCredential;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

/** Creates a credit at $occurredAt and returns the frozen rate_bp. */
function whRateResolvedAt(Merchant $merchant, MerchantUser $user, CarbonImmutable $occurredAt): int
{
    $customer = Customer::factory()->create();

    $transaction = app(ManualCreditService::class)->credit(
        $merchant->refresh(),
        $user,
        $customer->customer_code,
        'INV-'.Str::random(10),
        Laari::of(100_000),
        null,
        $occurredAt,
    );

    return $transaction->rate_bp;
}

it('refuses staff and rejects out-of-tier rates', function () {
    $staff = MerchantUser::factory()->for($this->merchant)->create(); // role staff

    $this->actingAs($staff, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertForbidden();

    // Structural bounds are 0.50%–20.00% (cap widening); 10.01%–20.00%
    // fail later as rate_not_priced against the active schedule, covered in
    // tests/Feature/Platform/CapWideningTest.php. The wire takes the percent
    // as a string or a JSON number, and junk of every shape is a field
    // error on cashback_rate_percent — never a silent reinterpretation.
    foreach (['0.49', '20.01', '0', -1, 'abc', ''] as $bad) {
        $this->actingAs($this->owner, 'merchant')
            ->postJson('/api/merchant/rate', ['cashback_rate_percent' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cashback_rate_percent');
    }

    // §4: finer than 0.01pp falls into no tier — rejected outright, as a
    // string and as a JSON number.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '2.505'])
        ->assertStatus(422);

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => 2.505])
        ->assertStatus(422);

    expect(MerchantRate::query()->count())->toBe(1);
});

it('applies an increase immediately and sale-time resolution honours it at occurred_at', function () {
    $this->seed(LedgerAccountSeeder::class);

    $now = CarbonImmutable::parse('2026-09-10T15:00:00+05:00')->utc();
    Carbon::setTestNow($now);

    $response = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '3.00'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '3.00')
        ->assertJsonPath('data.current.platform_fee_percent', '0.75')
        ->assertJsonPath('data.current.all_in_percent', '3.75')
        ->assertJsonPath('data.pending', null)
        ->assertJsonPath('change.applies', 'immediately')
        ->assertJsonPath('change.previous.cashback_rate_percent', '2.00')
        ->assertJsonPath('change.tier_changed', false);

    // History: old row closed at now, new row open-ended from now.
    $rows = MerchantRate::query()->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rate_bp)->toBe(200)
        ->and($rows[0]->effective_to->equalTo($now))->toBeTrue()
        ->and($rows[1]->rate_bp)->toBe(300)
        ->and($rows[1]->effective_from->equalTo($now))->toBeTrue()
        ->and($rows[1]->effective_to)->toBeNull()
        ->and($rows[1]->created_by)->toBe($this->owner->id);

    // A sale at the change instant already earns the new rate; one from
    // a minute earlier still resolves to the old rate.
    expect(whRateResolvedAt($this->merchant, $this->owner, $now))->toBe(300)
        ->and(whRateResolvedAt($this->merchant, $this->owner, $now->subMinute()))->toBe(200);
});

it('schedules a decrease for the next business-day midnight and resolution flips exactly there', function () {
    $this->seed(LedgerAccountSeeder::class);

    // 22:00 in Malé; the decrease must land at 00:00 next day UTC+5.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T22:00:00+05:00'));
    $boundary = CarbonImmutable::parse('2026-09-11T00:00:00+05:00')->utc();

    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.00'])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.pending.cashback_rate_percent', '1.00')
        ->assertJsonPath('data.pending.platform_fee_percent', '0.50')
        ->assertJsonPath('data.pending.all_in_percent', '1.50')
        ->assertJsonPath('change.applies', 'next_business_midnight')
        ->assertJsonPath('change.effective_at', '2026-09-11T00:00:00+05:00')
        ->assertJsonPath('change.tier_changed', true);

    $rows = MerchantRate::query()->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rate_bp)->toBe(200)
        ->and($rows[0]->effective_to->equalTo($boundary))->toBeTrue()
        ->and($rows[1]->rate_bp)->toBe(100)
        ->and($rows[1]->effective_from->equalTo($boundary))->toBeTrue()
        ->and($rows[1]->effective_to)->toBeNull();

    // Cross the boundary, then resolve two sales that straddle it: 23:59
    // still earns the advertised 2%, 00:01 earns the reduced 1%.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-11T00:30:00+05:00'));

    expect(whRateResolvedAt($this->merchant, $this->owner, CarbonImmutable::parse('2026-09-10T23:59:00+05:00')->utc()))->toBe(200)
        ->and(whRateResolvedAt($this->merchant, $this->owner, CarbonImmutable::parse('2026-09-11T00:01:00+05:00')->utc()))->toBe(100);
});

it('replaces a pending decrease with the newer change', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T10:00:00+05:00'));
    $boundary = CarbonImmutable::parse('2026-09-11T00:00:00+05:00')->utc();

    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.50'])->assertOk();
    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.00'])->assertOk();

    // The 150 row is gone; exactly one future row remains, at 100.
    $future = MerchantRate::query()->where('effective_from', '>', CarbonImmutable::now('UTC'))->get();
    expect($future)->toHaveCount(1)
        ->and($future[0]->rate_bp)->toBe(100)
        ->and($future[0]->effective_from->equalTo($boundary))->toBeTrue()
        ->and(MerchantRate::query()->count())->toBe(2);

    // An increase now cancels the pending decrease entirely and applies
    // immediately.
    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '5.00'])->assertOk();

    $rows = MerchantRate::query()->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->rate_bp)->toBe(200)
        ->and($rows[0]->effective_to->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
        ->and($rows[1]->rate_bp)->toBe(500)
        ->and($rows[1]->effective_to)->toBeNull()
        ->and(MerchantRate::query()->where('rate_bp', 100)->count())->toBe(0);
});

it('queues merchant.rate_changed with the openapi payload for subscribed vendors', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T22:00:00+05:00'));

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
        'events' => [WebhookEvents::MERCHANT_RATE_CHANGED],
        'active' => true,
    ]);

    // Decrease: effective_at is next midnight UTC+5, not now.
    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.00'])->assertOk();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->event)->toBe('merchant.rate_changed')
        // toEqual: jsonb round-trips with canonical key order.
        ->and($delivery->payload['data'])->toEqual([
            'merchant_id' => $this->merchant->id,
            'cashback_rate_percent' => '1.00',
            'platform_fee_percent' => '0.50',
            'previous_cashback_rate_percent' => '2.00',
            'previous_platform_fee_percent' => '0.75',
            'effective_at' => '2026-09-11T00:00:00+05:00',
        ]);

    Queue::assertPushed(SendWebhook::class, fn (SendWebhook $job) => $job->deliveryId === $delivery->id);

    // Re-posting the SAME standing rate with no pending change is a no-op
    // and must not spam vendors. First cancel the pending decrease (which
    // does notify), then repeat the current rate.
    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '2.00'])->assertOk();
    expect(WebhookDelivery::query()->count())->toBe(2);

    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '2.00'])->assertOk();
    expect(WebhookDelivery::query()->count())->toBe(2);
});

it('shows current and pending on GET /merchant/rate for any merchant user', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10T22:00:00+05:00'));

    $this->actingAs($this->owner, 'merchant')->postJson('/api/merchant/rate', ['cashback_rate_percent' => '1.00'])->assertOk();

    $staff = MerchantUser::factory()->for($this->merchant)->create();

    $this->actingAs($staff, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.current.platform_fee_percent', '0.75')
        ->assertJsonPath('data.current.all_in_percent', '2.75')
        ->assertJsonPath('data.pending.cashback_rate_percent', '1.00')
        ->assertJsonPath('data.pending.effective_from', '2026-09-11T00:00:00+05:00');
});
