<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Promotion;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    $this->seed(LedgerAccountSeeder::class);

    $this->customer = Customer::factory()->create();
    $this->admin = AdminUser::factory()->create();
    $this->merchant = Merchant::factory()->create(['name' => 'Store A']);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
});

function submitClaim(object $test, array $overrides = []): TestResponse
{
    return $test->postJson('/api/customer/claims', [
        'merchant_slug' => $test->merchant->slug,
        'purchased_at' => now('Indian/Maldives')->subDays(10)->toDateString(),
        'amount_laari' => 33333,
        'receipt_no' => 'RCPT-1001',
        'note' => 'Cashier said the system was down.',
        ...$overrides,
    ]);
}

it('submits a claim within the 90-day window', function () {
    $this->actingAs($this->customer, 'customer');

    submitClaim($this)
        ->assertCreated()
        ->assertJsonPath('data.state', 'open')
        ->assertJsonPath('data.merchant.name', 'Store A')
        ->assertJsonPath('data.amount_laari', 33333)
        ->assertJsonPath('data.receipt_no', 'RCPT-1001');

    expect(Claim::query()->count())->toBe(1);

    // Day 90 exactly still passes.
    submitClaim($this, [
        'purchased_at' => now('Indian/Maldives')->subDays(90)->toDateString(),
        'receipt_no' => 'RCPT-1002',
    ])->assertCreated();
});

it('rejects a purchase 91 days old or in the future', function () {
    $this->actingAs($this->customer, 'customer');

    submitClaim($this, ['purchased_at' => now('Indian/Maldives')->subDays(91)->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purchased_at');

    submitClaim($this, ['purchased_at' => now('Indian/Maldives')->addDay()->toDateString()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purchased_at');
});

it('requires receipt number and a positive integer amount', function () {
    $this->actingAs($this->customer, 'customer');

    submitClaim($this, ['receipt_no' => null])->assertUnprocessable();
    submitClaim($this, ['amount_laari' => 0])->assertUnprocessable();
    submitClaim($this, ['amount_laari' => 12.5])->assertUnprocessable();
});

it('lists only the customer’s own claims', function () {
    $this->actingAs($this->customer, 'customer');
    submitClaim($this)->assertCreated();

    $other = Customer::factory()->create();
    Claim::query()->create([
        'merchant_id' => $this->merchant->id,
        'customer_id' => $other->id,
        'claimed_date' => now()->subDays(5)->toDateString(),
        'claimed_amount_laari' => 9999,
        'receipt_no' => 'RCPT-OTHER',
        'state' => 'open',
    ]);

    $this->getJson('/api/customer/claims')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.receipt_no', 'RCPT-1001');
});

it('approves a claim: origin-claim transaction, ceiling money, accrual journal, linkage', function () {
    $this->actingAs($this->customer, 'customer');
    $claimId = submitClaim($this)->json('data.id');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$claimId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');

    $claim = Claim::query()->findOrFail($claimId);
    expect($claim->resolved_by)->toBe($this->admin->id);
    expect($claim->resolved_at)->not->toBeNull();
    expect($claim->resulting_transaction_id)->not->toBeNull();

    $tx = Transaction::query()->findOrFail($claim->resulting_transaction_id);

    // The merchant funds a missed REAL sale at the rate effective on the
    // purchase date, with normal §4 ceiling money:
    // 33333 × 200bp → ceil(666.66) = 667; fee 33333 × 75bp → ceil(249.99) = 250.
    expect($tx->origin)->toBe('claim');
    expect($tx->customer_id)->toBe($this->customer->id);
    expect($tx->merchant_id)->toBe($this->merchant->id);
    expect($tx->invoice_no)->toBe('RCPT-1001');
    expect($tx->eligible_laari)->toBe(33333);
    expect($tx->rate_bp)->toBe(200);
    expect($tx->fee_bp)->toBe(75);
    expect($tx->cashback_laari)->toBe(667);
    expect($tx->fee_laari)->toBe(250);
    expect($tx->state->value)->toBe('payable_unfunded');
    expect($tx->due_at)->not->toBeNull();

    // Every hop is evidenced: creation, validation, payable.
    expect($tx->events()->orderBy('id')->pluck('to_state')->all())
        ->toBe(['tracked', 'awaiting_validation', 'payable_unfunded']);
    expect($tx->events()->orderBy('id')->first()->reason_code)->toBe('claim_approved');

    // The accrual journal posted and balances: DR receivable 917 /
    // CR liability 667 + fee revenue 250.
    $journal = DB::table('ledger_journals')
        ->where('reference_type', 'transaction')
        ->where('reference_id', $tx->id)
        ->first();
    expect($journal)->not->toBeNull();

    $entries = DB::table('ledger_entries')->where('journal_id', $journal->id)->get();
    expect((int) $entries->sum('debit_laari'))->toBe(917);
    expect((int) $entries->sum('credit_laari'))->toBe(917);

    $receivableId = LedgerAccount::query()->where('code', AccountCode::MerchantReceivable->value)->value('id');
    $liabilityId = LedgerAccount::query()->where('code', AccountCode::CustomerCashbackLiability->value)->value('id');
    expect((int) $entries->firstWhere('account_id', $receivableId)->debit_laari)->toBe(917);
    expect((int) $entries->firstWhere('account_id', $liabilityId)->credit_laari)->toBe(667);

    // The customer now sees it pending with the settlement-window reason.
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.status_reason', 'merchant_settlement_window')
        ->assertJsonPath('data.0.cashback_laari', 667);
});

it('freezes the rate at the purchase date, not today’s rate', function () {
    // Rate history: 200bp until 5 days ago, 500bp since.
    MerchantRate::query()->where('merchant_id', $this->merchant->id)
        ->update(['effective_to' => now()->subDays(5)]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500,
        'effective_from' => now()->subDays(5),
        'effective_to' => null,
    ]);

    $this->actingAs($this->customer, 'customer');
    // Purchased 10 days ago — inside the 200bp era.
    $claimId = submitClaim($this)->json('data.id');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$claimId}/approve")
        ->assertOk();

    $tx = Transaction::query()->findOrFail(Claim::query()->findOrFail($claimId)->resulting_transaction_id);
    expect($tx->rate_bp)->toBe(200);
    expect($tx->cashback_laari)->toBe(667);
});

it('honours a published promotion covering the claimed purchase date — priced as the till would have', function () {
    // NULL-branch 500bp promo whose window covers the purchase date
    // (10 days ago) but has since ended — the claim still meets its terms.
    $promotion = Promotion::query()->create([
        'merchant_id' => $this->merchant->id,
        'rate_bp' => 500,
        'starts_at' => now()->subDays(12),
        'ends_at' => now()->subDays(8),
        'min_purchase_laari' => null,
        'max_cashback_per_customer_laari' => null,
        'status' => 'published',
        'published_at' => now()->subDays(13),
    ]);

    $this->actingAs($this->customer, 'customer');
    $claimId = submitClaim($this)->json('data.id');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$claimId}/approve")
        ->assertOk();

    $tx = Transaction::query()->findOrFail(
        Claim::query()->findOrFail($claimId)->resulting_transaction_id,
    );

    // The live path would have granted the promo 500bp with its 100bp fee
    // tier: ceil(33,333 × 0.05) = 1,667; fee ceil(33,333 × 0.01) = 334 —
    // not the standing 200/75. The row is stamped for cap accounting.
    expect($tx->rate_bp)->toBe(500);
    expect($tx->fee_bp)->toBe(100);
    expect($tx->cashback_laari)->toBe(1667);
    expect($tx->fee_laari)->toBe(334);
    expect($tx->promotion_id)->toBe($promotion->id);
});

it('keeps a resolved claim resolved — reject after approve conflicts and overwrites nothing', function () {
    $this->actingAs($this->customer, 'customer');
    $claimId = submitClaim($this)->json('data.id');

    $this->actingAs($this->admin, 'admin');
    $this->postJson("/api/admin/claims/{$claimId}/approve")->assertOk();

    // reject() re-reads under the same row lock approve() holds, so it can
    // only ever see the committed 'approved' state — 409, nothing changes.
    $this->postJson("/api/admin/claims/{$claimId}/reject", ['reason' => 'Too late.'])
        ->assertConflict();

    $claim = Claim::query()->findOrFail($claimId);
    expect($claim->state)->toBe('approved');
    expect($claim->resulting_transaction_id)->not->toBeNull();
    expect($claim->resolution_note)->not->toBe('Too late.');
});

it('blocks duplicate submissions while a claim is live, allows a refile only after rejection', function () {
    $this->actingAs($this->customer, 'customer');
    submitClaim($this)->assertCreated();

    // Identical resubmission while the first is still open: refused —
    // otherwise one customer floods the admin queue with copies.
    submitClaim($this)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt_no');
    expect(Claim::query()->count())->toBe(1);

    // Rejection clears the way: the customer may correct and refile.
    $firstId = Claim::query()->sole()->id;
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$firstId}/reject", ['reason' => 'Receipt unreadable.'])
        ->assertOk();

    $this->actingAs($this->customer, 'customer');
    $refileId = submitClaim($this)->assertCreated()->json('data.id');

    // Approved: a further identical claim is refused outright — the reward
    // was already credited.
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$refileId}/approve")
        ->assertOk();

    $this->actingAs($this->customer, 'customer');
    submitClaim($this)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt_no');
    expect(Claim::query()->count())->toBe(2);
});

it('refuses to approve twice, below-minimum, or for a suspended merchant', function () {
    $this->actingAs($this->customer, 'customer');
    $claimId = submitClaim($this)->json('data.id');
    $belowMinId = submitClaim($this, ['amount_laari' => 4999, 'receipt_no' => 'RCPT-MIN'])->json('data.id');

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/claims/{$claimId}/approve")->assertOk();
    $this->postJson("/api/admin/claims/{$claimId}/approve")->assertConflict();

    // Below the merchant's MVR 50 minimum: the equivalent live sale would
    // have earned nothing — refuse, don't mint a zero reward.
    $this->postJson("/api/admin/claims/{$belowMinId}/approve")->assertUnprocessable();

    $this->merchant->update(['status' => 'suspended']);
    $this->actingAs($this->customer, 'customer');
    $suspendedClaimId = submitClaim($this, ['receipt_no' => 'RCPT-SUSP'])->json('data.id');
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/claims/{$suspendedClaimId}/approve")->assertUnprocessable();

    expect(Transaction::query()->where('origin', 'claim')->count())->toBe(1);
});

it('rejects a claim only with a written reason', function () {
    $this->actingAs($this->customer, 'customer');
    $claimId = submitClaim($this)->json('data.id');

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/claims/{$claimId}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->postJson("/api/admin/claims/{$claimId}/reject", [
        'reason' => 'No matching sale in the merchant day book.',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected');

    $claim = Claim::query()->findOrFail($claimId);
    expect($claim->state)->toBe('rejected');
    expect($claim->resolved_by)->toBe($this->admin->id);
    expect($claim->resolution_note)->toBe('No matching sale in the merchant day book.');
    expect($claim->resulting_transaction_id)->toBeNull();

    // Terminal: no approve after reject.
    $this->postJson("/api/admin/claims/{$claimId}/approve")->assertConflict();

    // The customer sees the factual resolution note.
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/claims')
        ->assertOk()
        ->assertJsonPath('data.0.resolution_note', 'No matching sale in the merchant day book.');
});

it('filters the admin queue by state', function () {
    $this->actingAs($this->customer, 'customer');
    submitClaim($this)->assertCreated();
    $rejectId = submitClaim($this, ['receipt_no' => 'RCPT-2'])->json('data.id');

    $this->actingAs($this->admin, 'admin');
    $this->postJson("/api/admin/claims/{$rejectId}/reject", ['reason' => 'Duplicate.'])->assertOk();

    $this->getJson('/api/admin/claims?state=open')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.receipt_no', 'RCPT-1001')
        ->assertJsonPath('data.0.customer.customer_code', $this->customer->customer_code);

    $this->getJson('/api/admin/claims?state=rejected')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/admin/claims?state=bogus')->assertUnprocessable();
});

it('guards the surfaces: customers cannot work the queue, guests cannot claim', function () {
    $this->postJson('/api/customer/claims', [])->assertUnauthorized();
    $this->getJson('/api/admin/claims')->assertUnauthorized();

    $this->actingAs($this->customer, 'customer');
    $this->getJson('/api/admin/claims')->assertUnauthorized();
    $this->postJson('/api/admin/claims/1/approve')->assertUnauthorized();
});
