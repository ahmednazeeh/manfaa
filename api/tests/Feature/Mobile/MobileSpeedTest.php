<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PLAN-mobile-api.md M3 — one round trip on open, conditional reads, cursor
 * paging, and a query-count ceiling so an N+1 cannot creep back in unnoticed.
 */
function speedHeaders(Customer|MerchantUser $user, MobileAudience $audience): array
{
    $token = app(MobileTokenService::class)->issue($user, $audience, 'Device')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/** Runs a callback and returns how many queries it took. */
function queriesFor(Closure $callback): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// -------------------------------------------------------- customer home

it('answers the whole customer home screen in one request', function () {
    $customer = Customer::factory()->create(['payout_bank' => null]);

    $response = $this->withHeaders(speedHeaders($customer, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/home')
        ->assertOk();

    // Everything the first screen needs, with no second call.
    expect($response->json('data.customer.customer_code'))->toBe($customer->customer_code);
    expect($response->json('data.balance.confirmed_laari'))->toBeInt();
    expect($response->json('data.balance.pending_laari'))->toBeInt();
    expect($response->json('data.balance.paid_this_month_laari'))->toBeInt();
    expect($response->json('data.payout.minimum_laari'))->toBe(10000);
    expect($response->json('data.payout.next_window.starts_at'))->toBeString();
    expect($response->json('data.payout.has_account'))->toBeFalse();
});

it('never sums pending into the headline balance', function () {
    // §10, non-negotiable. The aggregate must not quietly "help" by adding
    // conditional money to the figure a customer reads as theirs.
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'cashback_laari' => 5000,
    ]);
    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::AwaitingValidation->value,
        'cashback_laari' => 4000,
    ]);

    $response = $this->withHeaders(speedHeaders($customer, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/home')
        ->assertOk();

    expect($response->json('data.balance.confirmed_laari'))->toBe(5000);
    expect($response->json('data.balance.pending_laari'))->toBe(4000);
});

it('serves an unchanged customer home as a 304', function () {
    $customer = Customer::factory()->create();
    $headers = speedHeaders($customer, MobileAudience::Customer);

    $etag = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/home')->assertOk()
        ->headers->get('ETag');

    expect($etag)->not->toBeNull();

    app('auth')->forgetGuards();

    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->getJson('/api/mobile/v1/customer/home')
        ->assertStatus(304);
});

it('stops serving a 304 once the balance actually moves', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();
    $headers = speedHeaders($customer, MobileAudience::Customer);

    $etag = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/home')->assertOk()
        ->headers->get('ETag');

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'cashback_laari' => 2500,
    ]);

    app('auth')->forgetGuards();

    // A cached 304 here would leave a customer staring at a stale balance
    // after they had just earned — the failure that makes caching dangerous.
    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->getJson('/api/mobile/v1/customer/home')
        ->assertOk()
        ->assertJsonPath('data.balance.confirmed_laari', 2500);
});

// -------------------------------------------------------- merchant home

it('answers the whole till home screen in one request', function () {
    $merchant = Merchant::factory()->create();
    $user = MerchantUser::factory()->for($merchant)->create();
    $customer = Customer::factory()->create();

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'occurred_at' => now(),
        'eligible_laari' => 100000,
        'cashback_laari' => 5000,
    ]);

    $response = $this->withHeaders(speedHeaders($user, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertOk();

    expect($response->json('data.merchant.status'))->toBe($merchant->status);
    expect($response->json('data.today.credit_count'))->toBe(1);
    expect($response->json('data.today.cashback_laari'))->toBe(5000);
    expect($response->json('data.outstanding.buckets'))->toBeArray();
    expect($response->json('data.open_settlement'))->toBeNull();
});

it('leaves reversed sales out of the day\'s tally', function () {
    $merchant = Merchant::factory()->create();
    $user = MerchantUser::factory()->for($merchant)->create();
    $customer = Customer::factory()->create();

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'occurred_at' => now(), 'cashback_laari' => 5000,
    ]);
    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Reversed->value,
        'occurred_at' => now(), 'cashback_laari' => 9999,
    ]);

    // A till that counted reversals would disagree with the receipt roll by
    // the end of a shift.
    $this->withHeaders(speedHeaders($user, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertOk()
        ->assertJsonPath('data.today.credit_count', 1)
        ->assertJsonPath('data.today.cashback_laari', 5000);
});

it('does not count another store\'s sales', function () {
    $mine = Merchant::factory()->create();
    $theirs = Merchant::factory()->create();
    $user = MerchantUser::factory()->for($mine)->create();
    $customer = Customer::factory()->create();

    Transaction::factory()->for($theirs)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'occurred_at' => now(), 'cashback_laari' => 7777,
    ]);

    $this->withHeaders(speedHeaders($user, MobileAudience::Merchant))
        ->getJson('/api/mobile/v1/merchant/home')
        ->assertOk()
        ->assertJsonPath('data.today.credit_count', 0);
});

// ------------------------------------------------------- cursor paging

it('walks history by cursor without repeating a row when new sales land', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    // Ten sales, oldest first, distinct instants so the order is total.
    foreach (range(1, 10) as $i) {
        Transaction::factory()->for($merchant)->for($customer)->create([
            'state' => TransactionState::Confirmed->value,
            'occurred_at' => now()->subMinutes(100 - $i),
            'cashback_laari' => $i * 100,
        ]);
    }

    $headers = speedHeaders($customer, MobileAudience::Customer);

    $first = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/transactions?per_page=4')
        ->assertOk();

    expect($first->json('data'))->toHaveCount(4);
    expect($first->json('page.has_more'))->toBeTrue();

    $cursor = $first->json('page.next_cursor');
    $seen = collect($first->json('data'))->pluck('id')->all();

    // A sale lands at the TOP between pages — precisely what breaks offset
    // paging, which would shift every row down one and re-serve a row the
    // client has already drawn.
    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
        'occurred_at' => now(),
        'cashback_laari' => 99999,
    ]);

    app('auth')->forgetGuards();

    $second = $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/transactions?per_page=4&cursor='.$cursor)
        ->assertOk();

    $secondIds = collect($second->json('data'))->pluck('id')->all();

    expect(array_intersect($seen, $secondIds))->toBeEmpty();
});

it('reports the end of the walk', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    Transaction::factory()->for($merchant)->for($customer)->create([
        'state' => TransactionState::Confirmed->value,
    ]);

    $this->withHeaders(speedHeaders($customer, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/transactions')
        ->assertOk()
        ->assertJsonPath('page.has_more', false)
        ->assertJsonPath('page.next_cursor', null);
});

it('never shows one customer another customer\'s history', function () {
    $merchant = Merchant::factory()->create();
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();

    Transaction::factory()->for($merchant)->for($theirs)->create([
        'state' => TransactionState::Confirmed->value,
    ]);

    $this->withHeaders(speedHeaders($mine, MobileAudience::Customer))
        ->getJson('/api/mobile/v1/customer/transactions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ------------------------------------------------------------ N+1 guard

it('does not issue a query per row when listing customer history', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    foreach (range(1, 3) as $i) {
        Transaction::factory()->for(Merchant::factory()->create())->for($customer)->create([
            'state' => TransactionState::Confirmed->value,
            'occurred_at' => now()->subMinutes($i),
        ]);
    }

    $headers = speedHeaders($customer, MobileAudience::Customer);

    // Time is frozen and the token is warmed FIRST, because Sanctum stamps
    // `last_used_at` on each authenticated request and Eloquent only writes
    // when the value actually changes. Left to real time, that write lands
    // in one measurement and not the other depending on whether a second
    // boundary happened to pass — which makes the comparison flap rather
    // than measure anything.
    $this->freezeTime();

    $this->withHeaders($headers)->getJson('/api/mobile/v1/customer/transactions')->assertOk();
    app('auth')->forgetGuards();

    $few = queriesFor(fn () => $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/transactions')->assertOk());

    // Ten more rows, each at a DIFFERENT merchant — the shape that turns a
    // missing eager-load into ten extra queries.
    foreach (range(4, 13) as $i) {
        Transaction::factory()->for(Merchant::factory()->create())->for($customer)->create([
            'state' => TransactionState::Confirmed->value,
            'occurred_at' => now()->subMinutes($i),
        ]);
    }

    app('auth')->forgetGuards();

    $many = queriesFor(fn () => $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/transactions')->assertOk());

    // Constant in the number of rows. Asserted as a RELATIONSHIP rather than
    // an absolute, so it stays true if the auth path's own query count ever
    // changes and still fails loudly the day the eager-load is dropped.
    expect($many)->toBe($few);
});

it('keeps the home aggregate to a small fixed number of queries', function () {
    $customer = Customer::factory()->create();
    $headers = speedHeaders($customer, MobileAudience::Customer);

    $count = queriesFor(fn () => $this->withHeaders($headers)
        ->getJson('/api/mobile/v1/customer/home')->assertOk());

    // Auth + balances + paid-this-month. A ceiling, not an exact figure, so
    // the test does not fail on an unrelated refactor — but it does fail if
    // somebody folds a per-row lookup into the aggregate.
    expect($count)->toBeLessThanOrEqual(6);
});

// --------------------------------------------------- public discovery

it('serves an unchanged discovery feed as a 304', function () {
    $etag = $this->getJson('/api/discover')->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/discover')
        ->assertStatus(304);
});

it('serves an unchanged store directory as a 304', function () {
    $etag = $this->getJson('/api/discover/merchants')->assertOk()->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/discover/merchants')
        ->assertStatus(304);
});
