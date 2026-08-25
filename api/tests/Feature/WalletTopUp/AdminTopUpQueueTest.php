<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\WalletFunding;
use App\Jobs\SendCustomerSms;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\WalletTopUp;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The admin fallback: the queue of claims the bank could not confirm, the
 * slip, and the two outcomes — Match (credits through the same path the
 * verifier uses) or Reject (with a reason the store sees).
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    Queue::fake();

    $this->merchant = Merchant::factory()->create([
        'name' => 'Agromart',
        'contact_phone' => '+9607771234',
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->account = PlatformBankAccount::query()->create([
        'bank_name' => 'bml',
        'account_no' => '7730000757923',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
    ]);

    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

function submitTopUp(int $laari, ?string $bankRef): WalletTopUp
{
    test()->actingAs(test()->owner, 'merchant')
        ->post('/api/merchant/wallet/top-ups', [
            'amount' => $laari,
            'platform_bank_account_id' => test()->account->id,
            'bank_ref' => $bankRef,
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated();

    return WalletTopUp::query()->orderByDesc('id')->firstOrFail();
}

it('lists the queue with the merchant, the account and the slip meta', function (): void {
    $pending = submitTopUp(20000, 'BML-TOPUP-1');
    $rejected = submitTopUp(15000, null);
    $rejected->forceFill(['state' => 'rejected'])->save();

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/wallet-top-ups?state=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id)
        ->assertJsonPath('data.0.merchant.name', 'Agromart')
        ->assertJsonPath('data.0.platform_bank_account.bank_name', 'bml')
        ->assertJsonPath('data.0.has_slip', true)
        ->assertJsonPath('data.0.slip_mime', 'image/jpeg')
        ->assertJsonPath('data.0.state', 'pending');

    $this->getJson('/api/admin/wallet-top-ups')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/admin/wallet-top-ups?state=rejected')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/admin/wallet-top-ups?state=lost')->assertUnprocessable();
});

it('is admin-guard only, on every route', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $this->getJson('/api/admin/wallet-top-ups')->assertUnauthorized();
    $this->getJson("/api/admin/wallet-top-ups/{$topUp->id}/slip")->assertUnauthorized();
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match")->assertUnauthorized();
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/reject", ['reason' => 'nope'])->assertUnauthorized();

    // A merchant session does not open the admin queue — not even to its
    // own slip.
    $this->actingAs($this->owner, 'merchant');
    $this->getJson('/api/admin/wallet-top-ups')->assertUnauthorized();
    $this->getJson("/api/admin/wallet-top-ups/{$topUp->id}/slip")->assertUnauthorized();
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match")->assertUnauthorized();

    expect($topUp->refresh()->state)->toBe('pending');
});

it('matches a claim the merchant referenced and credits the wallet once, telling the store', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => 20000])
        ->assertOk()
        ->assertJsonPath('data.state', 'matched')
        ->assertJsonPath('data.auto_matched', false)
        ->assertJsonPath('data.matched_by', $this->admin->id)
        ->assertJsonPath('data.matched_trx_id', 'BML-TOPUP-1')
        // What the reviewer read on the statement, recorded beside the
        // claim it happens to agree with.
        ->assertJsonPath('data.received_laari', 20000)
        ->assertJsonPath('data.amount_differs', false);

    $wallet = $this->merchant->wallet()->sole();
    $movement = $wallet->transactions()->sole();

    expect($wallet->balance_laari)->toBe(20000)
        ->and($movement->bank_ref)->toBe('BML-TOPUP-1')
        ->and($topUp->refresh()->wallet_transaction_id)->toBe($movement->id)
        ->and($topUp->matched_at)->not->toBeNull()
        ->and(DB::table('ledger_journals')->where('description', 'Merchant wallet top-up')->count())->toBe(1)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(20000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    Queue::assertPushed(SendCustomerSms::class, 1);

    // The merchant's wallet now shows the balance and no pending claim.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/wallet')
        ->assertJsonPath('data.balance_laari', 20000)
        ->assertJsonPath('data.pending_top_ups', []);

    // Matching again is a conflict, and credits nothing.
    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => 20000])
        ->assertConflict();

    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(20000)
        ->and($this->merchant->wallet()->sole()->transactions()->count())->toBe(1);
});

it('records what the reviewer read on the statement, not what the merchant typed', function (): void {
    // The manual path used to credit the claim blindly — the one place a
    // typed number could still become money on its own authority. The
    // reviewer is holding the statement; they type what it says.
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => 10000])
        ->assertOk()
        ->assertJsonPath('data.state', 'matched')
        // The claim, preserved.
        ->assertJsonPath('data.amount_laari', 20000)
        // The fact, as stated.
        ->assertJsonPath('data.received_laari', 10000)
        ->assertJsonPath('data.amount_differs', true);

    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(10000)
        ->and($this->merchant->wallet()->sole()->transactions()->sole()->amount_laari)->toBe(10000)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(10000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // And the store is told the figure that landed, not the one they asked
    // for — one message, quoting both.
    Queue::assertPushed(SendCustomerSms::class, 1);
});

it('will not credit by hand without a figure, or on a figure of nothing', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $this->actingAs($this->admin, 'admin');

    // Deliberately NOT defaulted to the claim: a default would be the old
    // behaviour wearing a new field's name.
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('received_laari');

    foreach ([0, -500] as $figure) {
        $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => $figure])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('received_laari');
    }

    expect($topUp->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->exists())->toBeFalse();
});

it('needs a reference from the admin when the merchant gave none', function (): void {
    $topUp = submitTopUp(20000, null);

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_ref');

    expect($topUp->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->exists())->toBeFalse();

    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['bank_ref' => 'FT26235BDLZB', 'received_laari' => 20000])
        ->assertOk()
        ->assertJsonPath('data.state', 'matched')
        ->assertJsonPath('data.matched_trx_id', 'FT26235BDLZB');

    expect($this->merchant->wallet()->sole()->transactions()->sole()->bank_ref)->toBe('FT26235BDLZB');
});

it('refuses to credit a reference the wallet already holds', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    // Booked directly in the meantime (the admin top-up route).
    app(WalletFunding::class)->recordTopUp($this->merchant, Laari::of(20000), 'BML-TOPUP-1');

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => 20000])
        ->assertConflict();

    expect($topUp->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->sole()->balance_laari)->toBe(20000)
        ->and($this->merchant->wallet()->sole()->transactions()->count())->toBe(1);
});

it('refuses to spend one bank credit on two claims', function (): void {
    $first = submitTopUp(20000, null);
    $second = submitTopUp(20000, null);

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/wallet-top-ups/{$first->id}/match", ['bank_ref' => 'FT-ONE', 'received_laari' => 20000])->assertOk();
    $this->postJson("/api/admin/wallet-top-ups/{$second->id}/match", ['bank_ref' => 'FT-ONE', 'received_laari' => 20000])->assertConflict();

    expect($second->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->sole()->balance_laari)->toBe(20000);
});

it('refuses to credit by hand a reference another table already spent', function (): void {
    // An auto-verified customer order holds this credit under a different
    // spelling of the same reference; the admin's string must not slip past.
    Order::factory()->create([
        'total_payable_laari' => 20000,
        'payment_state' => 'verified',
        'matched_trx_id' => 'FT-ONE',
    ]);

    $claim = submitTopUp(20000, null);

    $this->actingAs($this->admin, 'admin')
        ->postJson("/api/admin/wallet-top-ups/{$claim->id}/match", ['bank_ref' => 'FT-ONE', 'received_laari' => 20000])
        ->assertConflict();

    expect($claim->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->exists())->toBeFalse();
});

it('shows the bank watch as it stands on the row', function (): void {
    $claim = submitTopUp(20000, 'FT-WATCH');

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/wallet-top-ups?state=pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $claim->id)
        ->assertJsonPath('data.0.poll_until', $claim->poll_until?->toIso8601String())
        ->assertJsonPath('data.0.poll_attempts', 0);
});

it('rejects a claim with a reason the store is told, moving no money', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/reject", ['reason' => 'No such transfer on the statement.'])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.rejected_reason', 'No such transfer on the statement.')
        ->assertJsonPath('data.rejected_by', $this->admin->id);

    expect($topUp->refresh()->rejected_at)->not->toBeNull()
        ->and($this->merchant->wallet()->exists())->toBeFalse();

    Queue::assertPushed(SendCustomerSms::class, 1);

    // Decided: neither outcome can be applied again.
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/reject", ['reason' => 'again'])->assertConflict();
    $this->postJson("/api/admin/wallet-top-ups/{$topUp->id}/match", ['received_laari' => 20000])->assertConflict();

    // The merchant sees it REFUSED, with the reason to act on — not gone —
    // and may claim the same reference again once the problem is sorted.
    $this->actingAs($this->owner, 'merchant')
        ->getJson('/api/merchant/wallet')
        ->assertJsonCount(1, 'data.pending_top_ups')
        ->assertJsonPath('data.pending_top_ups.0.state', 'rejected')
        ->assertJsonPath('data.pending_top_ups.0.rejected_reason', 'No such transfer on the statement.');

    submitTopUp(20000, 'BML-TOPUP-1');
});

it('streams the slip to an admin with the stored mime and nosniff', function (): void {
    $topUp = submitTopUp(20000, 'BML-TOPUP-1');

    $response = $this->actingAs($this->admin, 'admin')
        ->get("/api/admin/wallet-top-ups/{$topUp->id}/slip")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain("top-up-{$topUp->id}.jpg");

    // The file gone from disk is a 404, not a 500.
    Storage::disk('slips')->delete($topUp->slip_path);

    $this->get("/api/admin/wallet-top-ups/{$topUp->id}/slip")->assertNotFound();
    $this->get('/api/admin/wallet-top-ups/999999/slip')->assertNotFound();
});
