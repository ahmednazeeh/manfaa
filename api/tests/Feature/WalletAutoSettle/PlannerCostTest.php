<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\WalletAutoSettler;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * What the hourly run pays to decide, per merchant with a balance. The
 * planner is the only part of the run that grew when the discount band was
 * fixed (owner, 2026-08-25), and it grew ONLY on the branch that needs it.
 */
it('plans an affordable board in ONE query and a banded one in three', function (): void {
    $fixture = PromptFixture::fourLines();

    // The platform settings the discount reads are cached for 60s; warm them
    // so the count measures the PLANNER, not a cold cache.
    app(PlatformConfig::class)->promptDiscountRateBp();
    app(PlatformConfig::class)->promptDiscountMaxAgeDays();

    $settler = app(WalletAutoSettler::class);

    $count = function (callable $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // 11,825 covers every line outright: the walk answers on its own.
    $whole = $count(fn () => $settler->plan($fixture->merchant, 11_825));

    // 11,700 is in the band, so the board is priced: the walk, the discount's
    // own read of those lines, and its "is anything left outstanding?" check.
    $banded = $count(fn () => $settler->plan($fixture->merchant, 11_700));

    // 1,000 buys nothing; the same three questions are asked and the prefix
    // comes back empty.
    $nothing = $count(fn () => $settler->plan($fixture->merchant, 1_000));

    expect($whole)->toBe(1)
        ->and($banded)->toBe(3)
        ->and($nothing)->toBe(3);
});
