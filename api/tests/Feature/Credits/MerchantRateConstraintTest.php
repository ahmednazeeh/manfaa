<?php

declare(strict_types=1);

use App\Models\Merchant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects a rate_bp outside the tier range through the check constraint', function (int $rateBp) {
    // §4: 50–1000bp spans the fee tiers exactly — 49 or 1001 falls into no
    // tier, so the schema refuses it before any resolution logic can run.
    $merchant = Merchant::factory()->create();

    expect(fn () => DB::table('merchant_rates')->insert([
        'merchant_id' => $merchant->id,
        'rate_bp' => $rateBp,
        'effective_from' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([49, 0, -200, 1001, 10000]);

it('accepts the 50bp and 1000bp tier boundaries', function () {
    $merchant = Merchant::factory()->create();

    DB::table('merchant_rates')->insert([
        [
            'merchant_id' => $merchant->id,
            'rate_bp' => 50,
            'effective_from' => now()->subYear(),
            'effective_to' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'merchant_id' => $merchant->id,
            'rate_bp' => 1000,
            'effective_from' => now(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(DB::table('merchant_rates')->where('merchant_id', $merchant->id)->count())->toBe(2);
});
