<?php

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A cashier who keys the wrong total needs to fix it, and a sale that never
 * happened needs to come off. Both only while the merchant's own validation
 * window is open — after that the money is inside the §7 clock and the
 * correction is the platform's to make.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'status' => 'active',
        'validation_window_days' => 2,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

function credit(array $payload = []): int
{
    return test()->actingAs(test()->owner, 'merchant')
        ->postJson('/api/merchant/credits', array_merge([
            'customer_code' => test()->customer->customer_code,
            'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 9999),
            'eligible_amount' => 118000,
        ], $payload))
        ->assertCreated()
        ->json('data.id');
}

it('corrects a mistyped total and moves the ledger with it', function () {
    // 118,000 @ 2.00% = 2,360 cashback, fee tier 0.75% = 885.
    $id = credit();

    expect((new Balances)->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2360);

    // The cashier meant 11,800 — a tenth of what was rung up.
    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertOk()
        ->assertJsonPath('data.eligible_laari', 11800)
        // ceil(11,800 × 200 ÷ 10,000) = 236; fee ceil(11,800 × 75 ÷ 10,000) = 89.
        ->assertJsonPath('data.cashback_laari', 236)
        ->assertJsonPath('data.fee_laari', 89)
        // The terms are untouched — an amendment fixes the amount, never
        // the rate the sale was rung up under.
        ->assertJsonPath('data.cashback_rate_percent', '2.00');

    // The ledger holds the NEW figure only: the old accrual came off in
    // full and the new one went on in full.
    $balances = new Balances;
    expect($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(236)
        ->and($balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(89)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(236 + 89);
});

it('keeps an audit trail of what the figures were', function () {
    $id = credit();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertOk();

    $event = Transaction::query()->findOrFail($id)->events()
        ->where('reason_code', 'amended')->sole();

    expect($event->meta['before']['eligible_laari'])->toBe(118000)
        ->and($event->meta['before']['cashback_laari'])->toBe(2360)
        ->and($event->meta['after']['eligible_laari'])->toBe(11800)
        ->and($event->meta['after']['cashback_laari'])->toBe(236)
        ->and($event->actor_type)->toBe('merchant_user');
});

it('zeroes a sale corrected below the store minimum, and says why', function () {
    $id = credit();

    // Below min_eligible_laari (5,000) the sale is recorded but earns
    // nothing — the same rule creation applies.
    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 900])
        ->assertOk()
        ->assertJsonPath('data.cashback_laari', 0)
        ->assertJsonPath('data.fee_laari', 0)
        ->assertJsonPath('data.reason_code', 'below_minimum');

    expect((new Balances)->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0);
});

it('replaces the category split wholesale rather than patching it', function () {
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'name_dv' => 'މޭވާ', 'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);

    $id = credit([
        'eligible_amount' => 100000,
        'lines' => [
            ['category' => 'fruits', 'amount_laari' => 40000],
            ['category' => null, 'amount_laari' => 60000],
        ],
    ]);

    // 60,000 @ 2.00% = 1,200; the fruit line earns nothing.
    expect(Transaction::query()->findOrFail($id)->cashback_laari)->toBe(1200);

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", [
            'eligible_amount' => 50000,
            'lines' => [
                ['category' => 'fruits', 'amount_laari' => 10000],
                ['category' => null, 'amount_laari' => 40000],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.eligible_laari', 50000)
        // 40,000 @ 2.00% = 800.
        ->assertJsonPath('data.cashback_laari', 800)
        ->assertJsonCount(2, 'data.lines');

    $lines = TransactionLine::query()->where('transaction_id', $id)->orderBy('sort')->get();

    expect($lines)->toHaveCount(2)
        ->and((int) $lines->sum('cashback_laari'))->toBe(800)
        ->and($lines->first()->amount_laari)->toBe(10000);
});

it('refuses lines that do not add up, leaving the sale untouched', function () {
    $id = credit();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", [
            'eligible_amount' => 100000,
            'lines' => [['category' => null, 'amount_laari' => 60000]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'lines_sum_mismatch');

    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

it('cancels a sale outright and takes the cashback back off', function () {
    $id = credit();

    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/transactions/{$id}/cancel", ['reason' => 'refund'])
        ->assertOk()
        ->assertJsonPath('data.state', 'reversed');

    $balances = new Balances;
    expect($balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0);
});

it('refuses to amend or cancel once the window has closed', function () {
    $id = credit();
    $transaction = Transaction::query()->findOrFail($id);
    $transaction->forceFill(['state' => 'payable_unfunded'])->save();

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_amendable_state');

    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

/**
 * PLAN §1: a sale credited outside the validation window is final — the
 * merchant was warned before submitting. Neither correction reaches it.
 */
it('refuses both corrections on a backdated credit', function () {
    $id = credit();
    Transaction::query()->whereKey($id)->update(['backdated' => true]);

    $this->actingAs($this->owner, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertStatus(409)
        ->assertJsonPath('code', 'backdated_irreversible');

    $this->actingAs($this->owner, 'merchant')
        ->postJson("/api/merchant/transactions/{$id}/cancel", ['reason' => 'error'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'backdated_irreversible');
});

it('is manager work, not staff work', function () {
    $id = credit();
    $staff = MerchantUser::factory()->for($this->merchant)->create(['role' => 'staff']);

    $this->actingAs($staff, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertStatus(403);

    $this->actingAs($staff, 'merchant')
        ->postJson("/api/merchant/transactions/{$id}/cancel", ['reason' => 'error'])
        ->assertStatus(403);
});

it('never reaches another merchant sale', function () {
    $id = credit();
    $other = Merchant::factory()->create(['status' => 'active']);
    $intruder = MerchantUser::factory()->for($other)->owner()->create();

    $this->actingAs($intruder, 'merchant')
        ->patchJson("/api/merchant/transactions/{$id}", ['eligible_amount' => 11800])
        ->assertNotFound();

    expect(Transaction::query()->findOrFail($id)->eligible_laari)->toBe(118000);
});

it('starts new stores on a two-day validation window', function () {
    $fresh = Merchant::query()->create(['name' => 'Fresh Store', 'slug' => 'fresh-store', 'status' => 'draft']);

    // The COLUMN default is what a new store inherits; the factory in this
    // file sets its own window explicitly, which is exactly the point — an
    // existing store keeps whatever it trades under.
    expect($fresh->refresh()->validation_window_days)->toBe(2);
});
