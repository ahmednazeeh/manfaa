<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\ApiCredential;
use App\Models\FeeTierSchedule;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\PosVendor;
use App\Models\Promotion;
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
 * The schedule/rate COVERAGE INVARIANT (adversarial review of PLAN §13b
 * item 3): at every instant, every in-force standing rate and published
 * promotion must be priced by the fee tier schedule in force at that
 * instant — otherwise every credit for the merchant throws at billing time
 * (HTTP 500 inside the POS POST) and a published promotion cannot even be
 * cancelled (§7 offers no early end). Enforced from BOTH directions:
 *
 *  - TierScheduleService::create refuses a schedule whose ceiling is below
 *    a rate already sold for its coverage window;
 *  - rate changes and promotions are validated against every schedule
 *    governing the instants they will be in force (assertPricedThrough),
 *    not just the one active at validation time.
 *
 * Plus the self-rescue: a merchant stranded by legacy data can still SEE
 * their rate and decrease it — the change commits, responds 200 and emits
 * the webhook even though the outgoing rate has no priced fee.
 */
beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T12:00:00+05:00'));

    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    $this->owner = MerchantUser::factory()->owner()->for($this->merchant)->create();
    $this->admin = AdminUser::factory()->create();

    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subMonth(),
        'effective_to' => null,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Appends a schedule row directly, bypassing the admin endpoint's guard. */
function coverageSchedule(CarbonImmutable $effectiveFrom, array $tiers): FeeTierSchedule
{
    return FeeTierSchedule::query()->create([
        'effective_from' => $effectiveFrom->utc(),
        'tiers' => $tiers,
        'created_by' => null,
        'created_at' => CarbonImmutable::now('UTC'),
    ]);
}

function postSchedule(CarbonImmutable $effectiveFrom, array $tiers)
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/api/admin/platform/fee-tiers', [
            'effective_from' => $effectiveFrom->toIso8601String(),
            'tiers' => $tiers,
        ]);
}

it('refuses to publish a schedule whose ceiling is below a live standing rate', function () {
    coverageSchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 2000, 'fee_bp' => 50],
    ]);

    // Merchant legally sells 15% under the wide schedule.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 1500])
        ->assertOk();

    // Narrowing to 10% would strand that rate: every credit for the
    // merchant would fail from the moment it takes effect.
    postSchedule(CarbonImmutable::now()->addDay(), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
    ])
        ->assertStatus(422)
        ->assertSee('15.00');

    expect(FeeTierSchedule::query()->count())->toBe(2); // seeded + wide only

    // A ceiling that still covers the sold rate is fine.
    postSchedule(CarbonImmutable::now()->addDay(), [
        ['from_bp' => 50, 'to_bp' => 1500, 'fee_bp' => 50],
    ])->assertCreated();
});

it('refuses a ceiling below a published promotion it would cover — drafts never block', function () {
    // Published 8% promotion next week (seeded 50-1000 schedule prices it).
    $published = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 800,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(9)->toIso8601String(),
        ])->assertCreated()->json('data.id');
    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/promotions/{$published}/publish")
        ->assertOk();

    // A 9% DRAFT exists too — drafts are cheap and re-validated at publish,
    // so they must never veto the admin.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 900,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(9)->toIso8601String(),
        ])->assertCreated();

    // Below the published promo: refused (it cannot be cancelled — §7).
    postSchedule(CarbonImmutable::now()->addDay(), [
        ['from_bp' => 50, 'to_bp' => 499, 'fee_bp' => 50],
    ])
        ->assertStatus(422)
        ->assertSee('8.00');

    // Covering the published promo but not the draft: accepted.
    postSchedule(CarbonImmutable::now()->addDay(), [
        ['from_bp' => 50, 'to_bp' => 800, 'fee_bp' => 50],
    ])->assertCreated();

    // The now-stale 9% draft still LISTS (null fee — its window is governed
    // by the 800bp table) so the merchant can see and cancel it; it never
    // 500s the whole promotions page.
    $staleDraftId = $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/promotions?status=draft')
        ->assertOk()
        ->assertJsonPath('data.0.rate_bp', 900)
        ->assertJsonPath('data.0.fee_bp', null)
        ->assertJsonPath('data.0.all_in_bp', null)
        ->json('data.0.id');

    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/promotions/{$staleDraftId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('bounds the guard at the next later schedule — rates only in force after it do not block', function () {
    coverageSchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 2000, 'fee_bp' => 50],
    ]);

    // 15% promotion published for day 5-6.
    $promoId = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 1500,
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'ends_at' => now()->addDays(6)->toIso8601String(),
        ])->assertCreated()->json('data.id');
    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/promotions/{$promoId}/publish")
        ->assertOk();

    // A wide schedule already takes over at day 2 . . .
    postSchedule(CarbonImmutable::now()->addDays(2), [
        ['from_bp' => 50, 'to_bp' => 2000, 'fee_bp' => 40],
    ])->assertCreated();

    // . . . so a narrowing covering ONLY [day 1, day 2) never governs the
    // promo window and is legal.
    postSchedule(CarbonImmutable::now()->addDay(), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
    ])->assertCreated();

    // But an open-ended narrowing at day 3 WOULD govern the window: refused.
    postSchedule(CarbonImmutable::now()->addDays(3), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
    ])->assertStatus(422);
});

it('refuses a rate change that an already-published narrowing would strand', function () {
    coverageSchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 2000, 'fee_bp' => 50],
    ]);

    // Admin has already scheduled a narrowing to 10% for tonight.
    postSchedule(CarbonImmutable::now()->addHours(6), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
    ])->assertCreated();

    // 15% would be legal under the ACTIVE schedule but unpriced from
    // tonight — refused up front instead of stranding at 22:00.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 1500])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced')
        ->assertJsonPath('message', 'The current fee schedule prices rates up to 10.00%.');

    expect(MerchantRate::query()->count())->toBe(1); // nothing committed

    // A rate every current-and-future schedule prices goes through.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 900])
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 900);
});

it('refuses a promotion whose window an already-published narrowing covers, at draft and at publish', function () {
    coverageSchedule(CarbonImmutable::now('UTC')->subDay(), [
        ['from_bp' => 50, 'to_bp' => 2000, 'fee_bp' => 50],
    ]);

    // Draft 15% for next week while only the wide schedule governs: fine.
    $draftId = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 1500,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(9)->toIso8601String(),
        ])->assertCreated()->json('data.id');

    // Admin then schedules a narrowing to 10% for tonight (drafts do not
    // block it) — the draft's window is now governed by the narrow table.
    postSchedule(CarbonImmutable::now()->addHours(2), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
    ])->assertCreated();

    // Publish is refused: the window it would freeze cannot be priced, and
    // published promos cannot be cancelled.
    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/promotions/{$draftId}/publish")
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced');
    expect(Promotion::query()->findOrFail($draftId)->status)->toBe('draft');

    // Same refusal already at draft time for early feedback.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 1500,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(9)->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced');

    // A 15% window that ENDS before the narrowing takes effect is still
    // sellable — the window bound works on promotions too.
    $earlyId = $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/promotions', [
            'rate_bp' => 1500,
            'starts_at' => now()->addMinutes(30)->toIso8601String(),
            'ends_at' => now()->addMinutes(90)->toIso8601String(),
        ])->assertCreated()->json('data.id');
    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/promotions/{$earlyId}/publish")
        ->assertOk();
});

it('lets a stranded merchant see their rate and rescue it: the decrease commits, answers 200 and emits the webhook', function () {
    // Legacy stranded state (predates the coverage guard): standing 15%
    // while a 50-1000 schedule is in force. Built directly — the admin
    // endpoint refuses this today.
    MerchantRate::query()->update(['rate_bp' => 1500]);
    coverageSchedule(CarbonImmutable::now('UTC')->subHour(), [
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 50],
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

    // The rate page still loads — null fee fields, never a 500. The page IS
    // the rescue path.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/rate')
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 1500)
        ->assertJsonPath('data.current.fee_bp', null)
        ->assertJsonPath('data.current.all_in_bp', null);

    // Still-unpriced target: refused, nothing committed.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 1200])
        ->assertStatus(422)
        ->assertJsonPath('code', 'rate_not_priced');
    expect(MerchantRate::query()->count())->toBe(1);

    // The rescue itself: decrease to a priced rate. Commits, answers 200
    // (previous fee honestly null — it was never priced) and dispatches
    // merchant.rate_changed so tills learn the pending decrease.
    $this->actingAs($this->owner, 'merchant')
        ->postJson('/api/merchant/rate', ['rate_bp' => 1000])
        ->assertOk()
        ->assertJsonPath('data.current.rate_bp', 1500)
        ->assertJsonPath('data.pending.rate_bp', 1000)
        ->assertJsonPath('data.pending.fee_bp', 50)
        ->assertJsonPath('change.previous.rate_bp', 1500)
        ->assertJsonPath('change.previous.fee_bp', null)
        ->assertJsonPath('change.previous.all_in_bp', null)
        ->assertJsonPath('change.new.rate_bp', 1000)
        ->assertJsonPath('change.new.fee_bp', 50)
        ->assertJsonPath('change.applies', 'next_business_midnight');

    expect(MerchantRate::query()->count())->toBe(2);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->payload['data']['rate_bp'])->toBe(1000)
        ->and($delivery->payload['data']['fee_bp'])->toBe(50)
        ->and($delivery->payload['data']['previous_rate_bp'])->toBe(1500)
        ->and($delivery->payload['data']['previous_fee_bp'])->toBeNull();
});
