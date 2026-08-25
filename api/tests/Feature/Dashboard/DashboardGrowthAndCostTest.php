<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts who joined, in total and in the period', function (): void {
    // Two customers and a store from before the window...
    Carbon::setTestNow(CarbonImmutable::parse('2026-07-10T06:00:00+00:00'));
    ReportFixture::customer('Old Customer One');
    ReportFixture::customer('Old Customer Two');
    Merchant::factory()->create(['status' => 'active']);

    // ...one customer and two stores inside it, one of the stores still
    // waiting on approval...
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-10T06:00:00+00:00'));
    ReportFixture::customer('New Customer');
    Merchant::factory()->create(['status' => 'active']);
    Merchant::factory()->create(['status' => 'pending_review']);

    // ...and one of each AFTER it, which neither total may include in the
    // period counts.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-02T06:00:00+00:00'));
    ReportFixture::customer('Later Customer');
    Merchant::factory()->create(['status' => 'active']);

    $growth = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/dashboard?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->json('growth');

    expect($growth)->toBe([
        'customers' => ['total' => 4, 'new_in_period' => 1],
        'merchants' => [
            // Three of the four stores are trading; one is in the queue.
            'active_total' => 3,
            'new_active_in_period' => 1,
            // The queued store signed up in the window too, and a signup
            // wave stuck in review is a fact the panel must be able to show.
            'registered_in_period' => 2,
        ],
    ]);

    expect(Customer::query()->count())->toBe(4);
});

/*
 * WHAT THE PAGE COSTS. It loads on every admin's landing, so the number is
 * pinned rather than assumed. Everything below is an aggregate or a scalar
 * subselect over an indexed predicate — nothing is chunked, nothing walks
 * rows — and the count does not move with the size of the database, which is
 * the property that actually matters: the same query plan answers a month
 * with four sales and a month with forty thousand.
 */
it('answers a month in a bounded number of queries, whatever is in it', function (): void {
    $small = dashboardQueries($this->superadmin);

    // Ten times the month, same page.
    for ($i = 0; $i < 10; $i++) {
        $fixture = ReportFixture::payable([100_000, 50_000], merchantName: 'Shop '.$i);
        $fixture->payAndMatch($fixture->submit(), $fixture->dueTotal());
    }

    $large = dashboardQueries($this->superadmin);
    $plain = dashboardQueries($this->admin);

    expect($large)->toBe($small)
        // The money panel and the chart are what a plain admin does not pay
        // for: three report scopes over two periods, plus three for the days.
        ->and($plain)->toBeLessThan($large)
        ->and($large)->toBeLessThanOrEqual(30)
        ->and($plain)->toBeLessThanOrEqual(20);
});

/** Queries one dashboard read costs this admin, warm caches, session aside. */
function dashboardQueries(AdminUser $admin): int
{
    // Warm the platform settings cache and the guard: neither is the page.
    test()->actingAs($admin, 'admin')->getJson('/api/admin/dashboard?from=2026-08-01&to=2026-08-31')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($admin, 'admin')->getJson('/api/admin/dashboard?from=2026-08-01&to=2026-08-31')->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/*
 * ...AND WHAT EACH QUERY COSTS, which the count above is blind to: it pins
 * how many round trips the page makes, not how much of the database each one
 * reads. The three hot predicates are only cheap because they are indexed
 * (migration 2026_08_25_120000), and every one of them was a sequential scan
 * of the largest table on its path before it existed:
 *
 *   transaction_events (to_state, created_at)  PayoutReport::paidScope, run
 *                                              three times per superadmin load
 *   customers (created_at)                     GrowthCounts, on the PLAIN
 *   merchants (created_at)                     admin path every admin polls
 *
 * Dropping any of them is invisible to a query count and to every assertion
 * about a figure, so it is asserted here instead.
 */
it('keeps an index under every predicate the landing page polls on', function (): void {
    $indexes = collect(DB::select('select indexname from pg_indexes'))
        ->pluck('indexname')
        ->all();

    expect($indexes)
        ->toContain('transaction_events_to_state_created_at_index')
        ->toContain('customers_created_at_index')
        ->toContain('merchants_created_at_index');
});
