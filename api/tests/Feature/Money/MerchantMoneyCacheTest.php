<?php

declare(strict_types=1);

use App\Domain\Money\MerchantMoneyCache;
use App\Domain\Settlement\OutstandingSummary;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The phase-2 money cache (owner, 2026-08-23): version-keyed per merchant,
 * bumped by every money event, TTL only as the reaper. These tests prove
 * the three behaviours the design stands on: a hit skips the recompute, a
 * money event orphans the entry, and an exception is never cached.
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 0]);
    MerchantRate::factory()->for($this->merchant)->create(['rate_bp' => 200, 'effective_from' => now()->subYear(), 'effective_to' => null]);
    Customer::factory()->create(['customer_code' => '482917']);
    $this->cache = app(MerchantMoneyCache::class);
});

it('serves a cached read without recomputing, per merchant', function () {
    $runs = 0;
    $compute = function () use (&$runs): array {
        $runs++;

        return ['answer' => $runs];
    };

    expect($this->cache->remember(1, 'thing', $compute))->toBe(['answer' => 1])
        ->and($this->cache->remember(1, 'thing', $compute))->toBe(['answer' => 1])
        ->and($runs)->toBe(1)
        // Another merchant never shares the entry.
        ->and($this->cache->remember(2, 'thing', $compute))->toBe(['answer' => 2]);
});

it('orphans every cached read when the merchant version bumps', function () {
    $runs = 0;
    $compute = function () use (&$runs): array {
        $runs++;

        return ['answer' => $runs];
    };

    $this->cache->remember(1, 'a', $compute);
    $this->cache->remember(1, 'b', $compute);

    MerchantMoneyCache::bump(1);

    expect($this->cache->remember(1, 'a', $compute))->toBe(['answer' => 3])
        ->and($this->cache->remember(1, 'b', $compute))->toBe(['answer' => 4]);
});

it('never caches an exception', function () {
    $runs = 0;
    $boom = function () use (&$runs): array {
        $runs++;
        throw new RuntimeException('nothing to settle');
    };

    foreach ([1, 2] as $attempt) {
        $threw = false;

        try {
            $this->cache->remember(1, 'boom', $boom);
        } catch (RuntimeException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
        expect($runs)->toBe($attempt);
    }
});

it('a landed credit refreshes the outstanding a dashboard reads', function () {
    $summary = app(OutstandingSummary::class);
    $before = $summary->forMerchant($this->merchant);
    expect($before['total']['count'])->toBe(0);

    // Cached now: a second read with no event serves the same answer.
    expect($summary->forMerchant($this->merchant)['total']['count'])->toBe(0);

    // A sale through the real credit path (creation + makePayable both
    // bump). Backdated so it lands payable_unfunded — on the board.
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;
    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/transactions', [
            'invoice_no' => 'CACHE-1',
            'customer_ref' => '482917',
            'eligible_amount' => 10000,
            'occurred_at' => now()->subDays(30)->toIso8601String(),
        ])->assertCreated();

    $after = $summary->forMerchant($this->merchant);
    expect($after['total']['count'])->toBe(1);
});
