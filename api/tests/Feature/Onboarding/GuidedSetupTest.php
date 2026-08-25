<?php

declare(strict_types=1);

use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Onboarding\OnboardingGuide;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The guided-setup tasklist (owner, 2026-08-25): per PERSON, anchored on
 * their own first sight of it, gone five days later whatever they did with
 * it, and every item answered from real state rather than from a box
 * somebody ticked.
 */
afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** A store and its owner, with nothing done yet. */
function guideFixture(array $merchantAttributes = []): array
{
    $merchant = Merchant::factory()->draft()->create($merchantAttributes + [
        // The factory hands out a half bank identity; the tasklist asks for
        // the whole triple, so start from nothing.
        'bank_name' => null,
        'bank_account' => null,
        'bank_account_name' => null,
    ]);

    return [$merchant, MerchantUser::factory()->for($merchant)->owner()->create()];
}

/** The done-ness of every task, keyed by task key. */
function taskState(array $payload): array
{
    return collect($payload['tasks'])->mapWithKeys(
        fn (array $task): array => [$task['key'] => $task['done']],
    )->all();
}

it('answers every task from real state, and only from THIS store\'s state', function () {
    [$merchant, $owner] = guideFixture();
    $this->actingAs($owner, 'merchant');

    $payload = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect($payload['show'])->toBeTrue()
        ->and($payload['skipped'])->toBeFalse()
        ->and($payload['tour_completed'])->toBeFalse()
        ->and($payload['days_remaining'])->toBe(OnboardingGuide::WINDOW_DAYS)
        ->and($payload['tasks_total'])->toBe(5)
        ->and($payload['tasks_done'])->toBe(0)
        ->and($payload['all_done'])->toBeFalse()
        ->and(taskState($payload))->toBe([
            'finish_setup' => false,
            'bank_account' => false,
            'credit_customer' => false,
            'settle_bill' => false,
            'add_staff' => false,
        ]);

    // Every item carries what a sidebar needs to draw it and what a client
    // needs to hide what this person may not do.
    foreach ($payload['tasks'] as $task) {
        expect($task['label_en'])->not->toBeEmpty()
            ->and($task['label_dv'])->not->toBeEmpty()
            ->and($task['help_en'])->not->toBeEmpty()
            // Real Dhivehi in the dv keys, not English in a dv coat.
            ->and(preg_match('/[\x{0780}-\x{07BF}]/u', $task['label_dv']))->toBe(1)
            ->and(preg_match('/[\x{0780}-\x{07BF}]/u', $task['help_dv']))->toBe(1)
            ->and($task['permission'])->not->toBeEmpty()
            ->and($task['target'])->not->toBeEmpty()
            ->and($task['web_path'])->toStartWith('/');
    }

    // ANOTHER store doing all of it ticks nothing here.
    [$otherStore, $otherOwner] = guideFixture();
    Transaction::factory()->for($otherStore)->create();
    MerchantUser::factory()->for($otherStore)->staff()->create();
    $otherStore->update(['status' => 'active', 'bank_name' => 'bml', 'bank_account' => '7730000111222', 'bank_account_name' => 'Other Pvt Ltd']);
    Settlement::query()->create([
        'merchant_id' => $otherStore->id,
        'reference' => 'ST-2026-09001',
        'state' => 'awaiting_payment',
        'amount_due_laari' => 5000,
    ]);

    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data')))
        ->toBe([
            'finish_setup' => false,
            'bank_account' => false,
            'credit_customer' => false,
            'settle_bill' => false,
            'add_staff' => false,
        ]);

    // Now the real things happen, one at a time, to THIS store.

    // Setup: done the moment the store leaves the wizard's two statuses.
    $merchant->update(['status' => 'pending_review']);
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['finish_setup'])->toBeTrue();

    // Bank: the WHOLE identity, or nothing. A half identity matches no
    // payment, so a half identity is not a done task.
    $merchant->update(['bank_name' => 'bml', 'bank_account' => '7730000123456']);
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['bank_account'])->toBeFalse();

    $merchant->update(['bank_account_name' => 'Fresh Mart Pvt Ltd']);
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['bank_account'])->toBeTrue();

    // Credit: a transaction exists for this store.
    Transaction::factory()->for($merchant)->create();
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['credit_customer'])->toBeTrue();

    // Settle: a settlement exists for this store.
    Settlement::query()->create([
        'merchant_id' => $merchant->id,
        'reference' => 'ST-2026-09002',
        'state' => 'awaiting_payment',
        'amount_due_laari' => 11825,
    ]);
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['settle_bill'])->toBeTrue();

    // Staff: an account other than this one — and a DEACTIVATED account is
    // not a cashier, so it does not count.
    $dormant = MerchantUser::factory()->for($merchant)->staff()->create(['is_active' => false]);
    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['add_staff'])->toBeFalse();

    $dormant->update(['is_active' => true]);

    $final = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect(taskState($final)['add_staff'])->toBeTrue()
        ->and($final['tasks_done'])->toBe(5)
        ->and($final['all_done'])->toBeTrue()
        // Everything done is not the same as expired: the five days are the
        // owner's hard rule and nothing else closes the window early.
        ->and($final['show'])->toBeTrue();
});

it('completes the credit task on a REAL credit taken at the counter', function () {
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create(['validation_window_days' => 3, 'min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();
    Customer::factory()->create(['customer_code' => '482917', 'name' => 'Aisha Mohamed', 'status' => 'active']);

    $this->actingAs($staff, 'merchant');

    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['credit_customer'])->toBeFalse();

    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-9100',
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => now('Indian/Maldives')->subHour()->startOfMinute()->format('Y-m-d\TH:i:sP'),
    ])->assertCreated();

    expect(taskState($this->getJson('/api/merchant/onboarding')->json('data'))['credit_customer'])->toBeTrue();
});

it('stamps the five-day anchor once, on first sight, and never moves it', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25T19:30:00+00:00'));

    [, $owner] = guideFixture();
    $this->actingAs($owner, 'merchant');

    // Nothing is stamped by signing in — only by asking for the guide.
    expect($owner->refresh()->onboarding_started_at)->toBeNull();

    $first = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect($owner->refresh()->onboarding_started_at?->toIso8601String())
        ->toBe('2026-08-25T19:30:00+00:00')
        ->and($first['started_at'])->toBe('2026-08-25T19:30:00+00:00')
        ->and($first['expires_at'])->toBe('2026-08-30T19:30:00+00:00')
        ->and($first['days_remaining'])->toBe(5);

    // Two days later the anchor is exactly where it was; only the clock moved.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27T19:30:00+00:00'));

    $later = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect($later['started_at'])->toBe('2026-08-25T19:30:00+00:00')
        ->and($later['expires_at'])->toBe('2026-08-30T19:30:00+00:00')
        ->and($later['days_remaining'])->toBe(3)
        ->and($later['show'])->toBeTrue();

    // A write does not move it either.
    $this->postJson('/api/merchant/onboarding/tour')->assertOk();

    expect($owner->refresh()->onboarding_started_at?->toIso8601String())->toBe('2026-08-25T19:30:00+00:00');
});

it('shows on day five and is gone on day six, whichever side of the business day the anchor sits', function () {
    // 19:30 UTC is 00:30 the NEXT day in Indian/Maldives: the anchor's UTC
    // date and its business date disagree from the first second. The window
    // is five whole days from the INSTANT, so the answer is the same either
    // way — a store that signs up just before midnight gets five days, not
    // four days and half an hour.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25T19:30:00+00:00'));

    [, $owner] = guideFixture();
    $this->actingAs($owner, 'merchant');

    $this->getJson('/api/merchant/onboarding')->assertOk()->assertJsonPath('data.show', true);

    // Deep into day five, and past six business-date rollovers.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30T19:29:59+00:00'));

    $dayFive = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect($dayFive['show'])->toBeTrue()
        ->and($dayFive['expired'])->toBeFalse()
        ->and($dayFive['days_remaining'])->toBe(1)
        ->and($dayFive['tasks'])->not->toBeEmpty();

    // One second past five days: gone, whatever is still undone.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-30T19:30:01+00:00'));

    $daySix = $this->getJson('/api/merchant/onboarding')->assertOk()->json('data');

    expect($daySix['show'])->toBeFalse()
        ->and($daySix['expired'])->toBeTrue()
        ->and($daySix['days_remaining'])->toBe(0)
        // Nothing left to draw, and nothing computed to draw it with.
        ->and($daySix['tasks'])->toBe([])
        ->and($daySix['tasks_total'])->toBe(0);
});

it('gives an account that predates the feature no tasklist at all', function () {
    // The migration anchors every existing account on its own created_at,
    // so a shop that has been trading for a month does not get a "credit
    // your first customer" list on the deploy that ships this.
    [, $owner] = guideFixture();

    DB::table('merchant_users')->where('id', $owner->id)->update([
        'onboarding_started_at' => now()->subDays(30),
    ]);

    $this->actingAs($owner->refresh(), 'merchant');

    $this->getJson('/api/merchant/onboarding')
        ->assertOk()
        ->assertJsonPath('data.show', false)
        ->assertJsonPath('data.expired', true);
});

it('skips permanently, for one person only', function () {
    [$merchant, $owner] = guideFixture();
    $cashier = MerchantUser::factory()->for($merchant)->staff()->create();

    $this->actingAs($owner, 'merchant');

    $skipped = $this->postJson('/api/merchant/onboarding/skip')->assertOk()->json('data');

    expect($skipped['show'])->toBeFalse()
        ->and($skipped['skipped'])->toBeTrue()
        ->and($skipped['tasks'])->toBe([]);

    // Immediate: the next read is already without it.
    $this->getJson('/api/merchant/onboarding')
        ->assertOk()
        ->assertJsonPath('data.show', false)
        ->assertJsonPath('data.skipped', true);

    // Idempotent: skipping twice is skipping once, and the second call does
    // not move the stamp that evidences when they decided.
    $stamp = $owner->refresh()->onboarding_skipped_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

    $this->postJson('/api/merchant/onboarding/skip')->assertOk()->assertJsonPath('data.skipped', true);

    expect($owner->refresh()->onboarding_skipped_at?->toIso8601String())->toBe($stamp?->toIso8601String());

    CarbonImmutable::setTestNow();

    // The cashier's own five days are untouched — one staffer can never
    // skip another's, and there is no request shape that names another
    // account to try it with.
    $this->actingAs($cashier, 'merchant');

    $this->getJson('/api/merchant/onboarding')
        ->assertOk()
        ->assertJsonPath('data.show', true)
        ->assertJsonPath('data.skipped', false);

    expect($cashier->refresh()->onboarding_skipped_at)->toBeNull();

    // And the cashier skipping does not revive the owner's.
    $this->postJson('/api/merchant/onboarding/skip')->assertOk();

    $this->actingAs($owner, 'merchant');
    $this->getJson('/api/merchant/onboarding')->assertOk()->assertJsonPath('data.show', false);
});

it('records a finished tour without retiring the tasklist', function () {
    [, $owner] = guideFixture();
    $this->actingAs($owner, 'merchant');

    $done = $this->postJson('/api/merchant/onboarding/tour')->assertOk()->json('data');

    // Watching the tour is not the same as having credited anybody: the
    // prompt stops, the work does not.
    expect($done['tour_completed'])->toBeTrue()
        ->and($done['show'])->toBeTrue()
        ->and($done['skipped'])->toBeFalse()
        ->and($done['tasks_done'])->toBe(0);

    $stamp = $owner->refresh()->onboarding_tour_completed_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

    $this->postJson('/api/merchant/onboarding/tour')->assertOk()->assertJsonPath('data.tour_completed', true);

    expect($owner->refresh()->onboarding_tour_completed_at?->toIso8601String())->toBe($stamp?->toIso8601String());
});

it('costs one query while it is live and none once it is over', function () {
    [, $owner] = guideFixture();
    $this->actingAs($owner, 'merchant');

    // The FIRST read also stamps the anchor: one UPDATE, one tasklist read.
    DB::enableQueryLog();
    $this->getJson('/api/merchant/onboarding')->assertOk();
    expect(DB::getQueryLog())->toHaveCount(2);

    // Every page load after that — the sidebar case — is ONE query: a
    // single merchants row carrying the store's status, its bank identity
    // and three EXISTS on indexed merchant_id columns.
    DB::flushQueryLog();
    $this->getJson('/api/merchant/onboarding')->assertOk();
    expect(DB::getQueryLog())->toHaveCount(1);

    // Once it is skipped there is nothing to compute, so nothing is read.
    $this->postJson('/api/merchant/onboarding/skip')->assertOk();

    DB::flushQueryLog();
    $this->getJson('/api/merchant/onboarding')->assertOk()->assertJsonPath('data.show', false);
    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('serves the same person the same guide on the till app', function () {
    [$merchant, $owner] = guideFixture();

    $token = app(MobileTokenService::class)
        ->issue($owner, MobileAudience::Merchant, 'Counter tablet')->plainTextToken;

    $headers = ['Authorization' => 'Bearer '.$token];

    $this->getJson('/api/mobile/v1/merchant/onboarding', $headers)
        ->assertOk()
        ->assertJsonPath('data.show', true)
        ->assertJsonPath('data.tasks_total', 5);

    // Skipping on the phone puts it away on the website too: it is one
    // person's decision, not one surface's.
    $this->postJson('/api/mobile/v1/merchant/onboarding/skip', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.show', false);

    $this->actingAs($owner->refresh(), 'merchant');
    $this->getJson('/api/merchant/onboarding')->assertOk()->assertJsonPath('data.show', false);

    expect($merchant->exists)->toBeTrue();
});
