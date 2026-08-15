<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    Customer::factory()->create(['customer_code' => '482917']);

    // Categories EXIST but are not referenced — their mere presence must
    // not perturb the single-rate path.
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => true, 'sort' => 1,
    ]);
});

it('keeps the single-rate path byte-identical: the §4 fixture verbatim, no line rows, no lines key', function () {
    $this->actingAs($this->user, 'merchant');

    // PLAN §4 test fixture, verbatim (merchant at 200bp → fee tier 75bp):
    //   INV-1001 100,000 → 2,000 / 750
    //   INV-1002  50,000 → 1,000 / 375
    //   INV-1003 200,000 → 4,000 / 1,500
    //   INV-1004  80,000 → 1,600 / 600
    //   BATCH    430,000 → 8,600 / 3,225 (due 11,825)
    $fixture = [
        ['INV-1001', 100000, 2000, 750],
        ['INV-1002', 50000, 1000, 375],
        ['INV-1003', 200000, 4000, 1500],
        ['INV-1004', 80000, 1600, 600],
    ];

    foreach ($fixture as [$invoice, $eligible, $cashback, $fee]) {
        $response = $this->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => $invoice,
            'eligible_amount' => $eligible,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.cashback_rate_percent', '2.00')
            ->assertJsonPath('data.platform_fee_percent', '0.75')
            ->assertJsonPath('data.cashback_laari', $cashback)
            ->assertJsonPath('data.fee_laari', $fee)
            // The response shape is unchanged: no `lines` key at all.
            ->assertJsonMissingPath('data.lines');

        expect($response->json('data.state'))->toBe('awaiting_validation');
    }

    // Batch totals = sum of stored rounded lines (§4), and NO
    // transaction_lines rows exist anywhere on this path.
    expect((int) Transaction::query()->sum('cashback_laari'))->toBe(8600)
        ->and((int) Transaction::query()->sum('fee_laari'))->toBe(3225)
        ->and(TransactionLine::query()->count())->toBe(0);
});

it('keeps the /v1 single-rate response shape unchanged (no lines key, same integers)', function () {
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-1001',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])
        ->assertCreated()
        ->assertJsonPath('transaction.cashback_rate_percent', '2.00')
        ->assertJsonPath('transaction.platform_fee_percent', '0.75')
        ->assertJsonPath('transaction.cashback_laari', 2000)
        ->assertJsonPath('transaction.fee_laari', 750)
        ->assertJsonMissingPath('transaction.lines');

    expect(TransactionLine::query()->count())->toBe(0);
});
