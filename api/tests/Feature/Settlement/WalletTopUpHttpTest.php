<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\MerchantWallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->merchant = Merchant::factory()->create();
    $this->admin = AdminUser::factory()->create();
});

it('records a wallet top-up and refuses the same transfer twice', function () {
    $this->actingAs($this->admin, 'admin');

    $this->postJson("/api/admin/merchants/{$this->merchant->id}/wallet/top-ups", [
        'amount' => 20000,
        'bank_ref' => 'BML-TOPUP-1',
    ])
        ->assertCreated()
        ->assertJsonPath('data.balance_laari', 20000)
        ->assertJsonPath('data.transactions.0.type', 'top_up')
        ->assertJsonPath('data.transactions.0.amount_laari', 20000);

    // The same transfer recorded twice — double click, retried request — is
    // a conflict: no second movement, no second journal, balance unchanged.
    $this->postJson("/api/admin/merchants/{$this->merchant->id}/wallet/top-ups", [
        'amount' => 20000,
        'bank_ref' => 'BML-TOPUP-1',
    ])->assertConflict();

    $wallet = MerchantWallet::query()->where('merchant_id', $this->merchant->id)->sole();

    expect($wallet->balance_laari)->toBe(20000)
        ->and($wallet->transactions()->count())->toBe(1)
        ->and(DB::table('ledger_journals')->where('description', 'Merchant wallet top-up')->count())->toBe(1);

    // A different reference is a genuine second transfer.
    $this->postJson("/api/admin/merchants/{$this->merchant->id}/wallet/top-ups", [
        'amount' => 5000,
        'bank_ref' => 'BML-TOPUP-2',
    ])
        ->assertCreated()
        ->assertJsonPath('data.balance_laari', 25000);

    // The same reference is only unique per wallet — another merchant's
    // transfer may legitimately carry it.
    $other = Merchant::factory()->create();

    $this->postJson("/api/admin/merchants/{$other->id}/wallet/top-ups", [
        'amount' => 1000,
        'bank_ref' => 'BML-TOPUP-1',
    ])
        ->assertCreated()
        ->assertJsonPath('data.balance_laari', 1000);
});

it('validates the top-up payload', function () {
    $this->actingAs($this->admin, 'admin');

    $url = "/api/admin/merchants/{$this->merchant->id}/wallet/top-ups";

    // bank_ref is required — without it the idempotency key does not exist.
    $this->postJson($url, ['amount' => 20000])->assertUnprocessable();
    // amount is a positive integer of laari.
    $this->postJson($url, ['amount' => 0, 'bank_ref' => 'BML-X'])->assertUnprocessable();
    $this->postJson($url, ['amount' => 12.5, 'bank_ref' => 'BML-X'])->assertUnprocessable();

    expect(MerchantWallet::query()->count())->toBe(0);

    // An unknown merchant is a plain 404.
    $this->postJson('/api/admin/merchants/999999/wallet/top-ups', [
        'amount' => 20000,
        'bank_ref' => 'BML-X',
    ])->assertNotFound();
});

it('is admin-guard only', function () {
    $url = "/api/admin/merchants/{$this->merchant->id}/wallet/top-ups";
    $payload = ['amount' => 20000, 'bank_ref' => 'BML-GUARD-1'];

    $this->postJson($url, $payload)->assertUnauthorized();

    // A merchant token does not open the admin route.
    $merchantUser = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($merchantUser, 'merchant')->postJson($url, $payload)->assertUnauthorized();

    expect(MerchantWallet::query()->count())->toBe(0);
});
