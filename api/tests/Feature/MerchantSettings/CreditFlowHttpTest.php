<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The counter flow end-to-end over HTTP, exactly as apps/merchant's Credit
 * Customer screen drives it and as a STAFF user (credits are staff work —
 * §11, role matrix): masked-name lookup first, then the credit POST with a
 * Maldives wall-clock occurred_at carrying an explicit +05:00 offset, then
 * the double-submit landing on the duplicate-invoice 409.
 */
it('lets a staff user run lookup, credit, and duplicate 409 as the UI does', function () {
    $this->seed(LedgerAccountSeeder::class);

    $merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $staff = MerchantUser::factory()->for($merchant)->staff()->create();
    Customer::factory()->create([
        'customer_code' => '482917',
        'name' => 'Aisha Mohamed',
        'status' => 'active',
    ]);

    $this->actingAs($staff, 'merchant');

    // Step 1 — the screen confirms the person by masked name before anything
    // is submitted (§11 phone-recycling control).
    $this->getJson('/api/merchant/customers/lookup?code=482917')
        ->assertOk()
        ->assertExactJson([
            'valid' => true,
            'name' => 'Aisha Mohamed',
        ]);

    // Step 2 — the credit POST. The UI's toBusinessIso() sends the
    // datetime-local value with seconds zeroed and +05:00 explicit.
    $occurredLocal = now('Indian/Maldives')->subHour()->startOfMinute();
    $payload = [
        'customer_code' => '482917',
        'invoice_no' => 'INV-7001',
        'eligible_amount' => 125000,
        'sale_amount' => 125000,
        'occurred_at' => $occurredLocal->format('Y-m-d\TH:i:sP'), // …T16:04:00+05:00
    ];

    expect($payload['occurred_at'])->toEndWith('+05:00');

    $this->postJson('/api/merchant/credits', $payload)
        ->assertCreated()
        ->assertJsonPath('data.origin', 'manual')
        ->assertJsonPath('data.state', 'awaiting_validation')
        ->assertJsonPath('data.invoice_no', 'INV-7001')
        ->assertJsonPath('data.cashback_rate_percent', '2.00')
        ->assertJsonPath('data.cashback_laari', intdiv(125000 * 200 + 9999, 10000))
        ->assertJsonPath('data.fee_laari', intdiv(125000 * 75 + 9999, 10000));

    // The +05:00 wall clock landed as the correct UTC instant.
    expect(Transaction::query()->sole()->occurred_at->getTimestamp())
        ->toBe($occurredLocal->getTimestamp());

    // Step 3 — a double submit of the same invoice: 409, and nothing new is
    // written (one transaction, one accrual journal).
    $this->postJson('/api/merchant/credits', $payload)->assertConflict();

    expect(Transaction::query()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->count())->toBe(1);
});
