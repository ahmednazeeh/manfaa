<?php

declare(strict_types=1);

use App\Domain\Transfers\PayoutSender;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerPayout;
use App\Models\CustomerWallet;
use App\Models\TransferProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MP9 — the customer wallet and the money that leaves it
 * (owner decision 2026-08-19).
 *
 * These tests exist because this is the code that can pay somebody twice.
 */
beforeEach(function () {
    config(['services.transfer.api_key' => 'test-key']);

    $this->customer = Customer::factory()->create([
        'payout_bank' => 'BML',
        'payout_account' => '7730000123456',
        'payout_account_name' => 'Aishath Niza',
    ]);

    $this->profile = TransferProfile::query()->create([
        'name' => 'Faseyha Faisaa',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => '/faisanet',
        'from_account' => '90501400021681000',
        'active' => true,
        'is_default' => true,
    ]);

    $this->wallet = app(WalletService::class);
});

it('credits a wallet and keeps the ledger in step with the balance', function () {
    $this->wallet->credit($this->customer, 7800, 'refund', description: 'Refund');

    $wallet = CustomerWallet::query()->sole();

    expect($wallet->balance_laari)->toBe(7800)
        // The balance is a cache of the entries; the moment they disagree,
        // the number on a customer's screen stops being evidence.
        ->and((int) $wallet->entries()->sum('amount_laari'))->toBe(7800)
        ->and($wallet->entries()->sole()->balance_after_laari)->toBe(7800);
});

it('will not credit the same refund twice', function () {
    // Crediting one refund twice IS refunding twice.
    $this->wallet->credit($this->customer, 7800, 'refund', 'App\\Models\\CustomerRefund', 1);
    $this->wallet->credit($this->customer, 7800, 'refund', 'App\\Models\\CustomerRefund', 1);

    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(7800);
});

it('debits the balance when a withdrawal is REQUESTED, not when it is paid', function () {
    $this->wallet->credit($this->customer, 50000, 'refund');

    $payout = $this->wallet->requestWithdrawal($this->customer, 30000);

    // Leaving it spendable while a transfer sits in a queue is how the same
    // laari gets withdrawn twice.
    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(20000)
        ->and($payout->state)->toBe('pending')
        ->and($payout->internal_ref)->toStartWith('manfaa-w-');
});

it('refuses to withdraw more than is there', function () {
    $this->wallet->credit($this->customer, 5000, 'refund');

    expect(fn () => $this->wallet->requestWithdrawal($this->customer, 9000))
        ->toThrow(WalletException::class);

    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(5000);
});

it('refuses to withdraw with no bank account on file', function () {
    $this->customer->forceFill(['payout_account' => ''])->save();
    $this->wallet->credit($this->customer, 50000, 'refund');

    expect(fn () => $this->wallet->requestWithdrawal($this->customer->fresh(), 10000))
        ->toThrow(WalletException::class);
});

it('snapshots the bank details so a later edit cannot redirect a transfer', function () {
    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $this->customer->forceFill(['payout_account' => '9999999999999'])->save();

    expect($payout->fresh()->account)->toBe('7730000123456');
});

// ------------------------------------------------------------- the bank

it('records a successful transfer with the bank\'s reference', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-100'], 200)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $sent = app(PayoutSender::class)->send($payout);

    expect($sent->state)->toBe('sent')
        ->and($sent->trx_id)->toBe('TRX-100');
});

it('treats a duplicate that already SUCCEEDED as sent, and adopts its reference', function () {
    // The textbook double payment: read this 409 as a failure, retry, and
    // the customer is paid twice.
    Http::fake(['*' => Http::response([
        'existing' => ['status' => 'success', 'trx_id' => 'TRX-EXISTING', 'attempts' => 1],
    ], 409)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $sent = app(PayoutSender::class)->send($payout);

    expect($sent->state)->toBe('sent')
        ->and($sent->trx_id)->toBe('TRX-EXISTING')
        // And the money stays debited — it really did go.
        ->and(CustomerWallet::query()->sole()->balance_laari)->toBe(40000);
});

it('parks a dual-control transfer and never files the approval id as a bank reference', function () {
    Http::fake(['*' => Http::response([
        'status' => 'pending_approval',
        'trx_id' => '',
        'approval_id' => 'rec_884412',
    ], 200)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $parked = app(PayoutSender::class)->send($payout);

    expect($parked->state)->toBe('pending_approval')
        ->and($parked->approval_id)->toBe('rec_884412')
        // An approvals-queue record id is NOT a transaction reference.
        // Filing it as one would report an unmade payment as made.
        ->and($parked->trx_id)->toBeNull();
});

it('refuses to re-send a parked transfer', function () {
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_1'], 200)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);
    app(PayoutSender::class)->send($payout);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    // Alive, not failed. Sending again pays twice.
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/send")
        ->assertStatus(409)
        ->assertJsonPath('code', 'pending_approval');

    expect($payout->fresh()->attempts)->toBe(1);
});

it('cannot send the same laari twice after a refund to the wallet', function () {
    // The audit's finding: a retryable refusal returned the money to the
    // wallet AND left the row `failed`, which is sendable. An admin doing
    // the obvious thing — retry the failed transfer — sent money the
    // customer already had back, and could withdraw again.
    Http::fake(['*' => Http::response(['error_code' => 'invalid_account'], 400)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $failed = app(PayoutSender::class)->send($payout);

    expect($failed->state)->toBe('refunded');
    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(50000);

    // Terminal: the admin queue cannot re-send it.
    expect(in_array('refunded', CustomerPayout::SENDABLE, true))->toBeFalse();

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/send")
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_sendable');
});

it('puts the money back when the failure PROVES nothing left the bank', function () {
    // A refused account number was refused before anything moved, so the
    // customer gets their balance back and can correct the account.
    Http::fake(['*' => Http::response(['error_code' => 'invalid_account', 'error' => 'No such account'], 400)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $failed = app(PayoutSender::class)->send($payout);

    expect($failed->state)->toBe('refunded');
    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(50000);
});

it('keeps the money committed when the failure proves nothing at all', function () {
    // A 400 carrying a code we do not recognise is NOT proof. The balance
    // stays debited and a human looks — crediting a wallet for money that
    // did move is a second payment by another name.
    Http::fake(['*' => Http::response(['error_code' => 'bank_error', 'error' => 'Refused'], 400)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $failed = app(PayoutSender::class)->send($payout);

    expect($failed->state)->toBe('failed');
    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(40000);
});

it('leaves the money committed when the bank never answered', function () {
    // The dangerous case: the bank may well have moved it while we stopped
    // listening. Never automatically retryable.
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $failed = app(PayoutSender::class)->send($payout);

    expect($failed->state)->toBe('failed')
        ->and($failed->error_code)->toBe('no_response')
        ->and(CustomerWallet::query()->sole()->balance_laari)->toBe(40000);
});

it('sends the money as a decimal string built from integer laari', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'T1'], 200)]);

    $this->wallet->credit($this->customer, 123456, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 123456);

    app(PayoutSender::class)->send($payout);

    Http::assertSent(function ($request): bool {
        // 123456 laari is MVR 1234.56 — formatted from the integer, never
        // divided into a float. This is the one place our money crosses into
        // somebody else's number system.
        return $request['amount'] === '1234.56'
            && $request['from_account'] === '90501400021681000'
            && str_starts_with((string) $request['internal_ref'], 'manfaa-w-');
    });
});

it('posts to the profile\'s own endpoint', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'T1'], 200)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    app(PayoutSender::class)->send($this->wallet->requestWithdrawal($this->customer, 10000));

    Http::assertSent(fn ($request): bool => $request->url() === 'http://10.99.0.1:3005/faisanet/transfer');
});

it('cancels a pending payout and returns the money with a reason', function () {
    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/cancel", [
            'reason' => 'Customer asked to keep it in the wallet.',
        ])->assertOk();

    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(50000)
        ->and(CustomerWallet::query()->sole()->entries()->where('type', 'withdrawal_reversed')->count())->toBe(1);
});

it('will not cancel a transfer that is already parked', function () {
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_1'], 200)]);

    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);
    app(PayoutSender::class)->send($payout);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    // It may yet be approved. Returning the money now would pay twice.
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/cancel", ['reason' => 'Changed mind'])
        ->assertStatus(409);

    expect(CustomerWallet::query()->sole()->balance_laari)->toBe(40000);
});

it('lets an admin record a transfer made by hand', function () {
    // Every transfer, until the tunnel exists.
    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/mark-sent", ['trx_id' => 'MANUAL-778'])
        ->assertOk();

    expect($payout->fresh()->state)->toBe('sent')
        ->and($payout->fresh()->trx_id)->toBe('MANUAL-778');
});

it('never exposes the API key, only whether one is set', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $body = $this->actingAs($admin, 'admin')
        ->getJson('/api/admin/transfer-settings')->assertOk()->json();

    expect($body['data']['api_key_configured'])->toBeTrue()
        ->and(json_encode($body))->not->toContain('test-key');
});

it('lets an admin repoint the endpoint without a deploy', function () {
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    // The WireGuard peer does not exist yet — this has to change from a
    // screen the day it does.
    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/transfer-settings/profiles/{$this->profile->id}", [
            'base_url' => 'http://10.99.0.2:3005',
            'segment' => '/faisanet4',
            'from_account' => '90501480029671000',
            'dual_control' => true,
        ])->assertOk();

    expect($this->profile->fresh()->endpoint())->toBe('http://10.99.0.2:3005/faisanet4/transfer');
});

it('keeps exactly one default profile', function () {
    $second = TransferProfile::query()->create([
        'name' => 'Interbridge', 'base_url' => 'http://10.99.0.1:3005',
        'segment' => '/faisanet2', 'active' => true, 'is_default' => false,
    ]);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->patchJson("/api/admin/transfer-settings/profiles/{$second->id}", ['is_default' => true])
        ->assertOk();

    // "Whichever row happened to be first" is not a thing to send money
    // through.
    expect(TransferProfile::query()->where('is_default', true)->count())->toBe(1)
        ->and($this->profile->fresh()->is_default)->toBeFalse();
});

it('keeps an ordinary admin out of the money', function () {
    $this->wallet->credit($this->customer, 50000, 'refund');
    $payout = $this->wallet->requestWithdrawal($this->customer, 10000);

    $admin = AdminUser::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')->getJson('/api/admin/pending-payments')->assertOk();
    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/pending-payments/{$payout->id}/send")->assertForbidden();
});
