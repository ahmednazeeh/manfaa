<?php

declare(strict_types=1);

use App\Domain\Money\Laari;
use App\Domain\Settlement\WalletFunding;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('walks the full merchant + admin settlement flow over HTTP', function () {
    $this->actingAs($this->fixture->user, 'merchant');

    // Outstanding before settling: the whole §4 batch sits in the 0–5 bucket.
    $this->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4)
        ->assertJsonPath('data.total.payable_laari', 11825)
        ->assertJsonPath('data.total.payable_mvr', '118.25')
        ->assertJsonPath('data.buckets.0_5.count', 4)
        ->assertJsonPath('data.buckets.overdue.count', 0);

    // Build the batch: settle-all.
    $settlementId = $this->postJson('/api/merchant/settlements', ['settle_all' => true])
        ->assertCreated()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.reference', 'ST-2026-00001')
        ->assertJsonPath('data.amount_due_laari', 11825)
        ->assertJsonPath('data.amount_due_mvr', '118.25')
        ->assertJsonCount(4, 'data.lines')
        ->json('data.id');

    // Submit: lines freeze, batch awaits payment.
    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")
        ->assertOk()
        ->assertJsonPath('data.state', 'awaiting_payment');

    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")
        ->assertConflict();

    // Detail with lines, from the merchant's own listing.
    $this->getJson("/api/merchant/settlements/{$settlementId}")
        ->assertOk()
        ->assertJsonCount(4, 'data.lines')
        ->assertJsonPath('data.lines.0.due_laari', 2750);

    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Admin queue: the batch shows up filtered by state.
    $this->actingAs($this->admin, 'admin');

    $this->getJson('/api/admin/settlements?state=awaiting_payment')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $settlementId);

    $this->getJson('/api/admin/settlements?state=settled')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Record the claimed transfer (amount in integer laari), then match it.
    $paymentId = $this->postJson("/api/admin/settlements/{$settlementId}/payments", [
        'amount' => 11780,
        'bank_ref' => 'BML-20260805-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.amount_laari', 11780)
        ->json('data.id');

    // 11780 against 11825: the 45-laari shortfall is forgiven — settled.
    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_received_laari', 11780);

    $this->postJson("/api/admin/payments/{$paymentId}/match")
        ->assertConflict();

    $this->getJson("/api/admin/settlements/{$settlementId}")
        ->assertOk()
        ->assertJsonPath('data.lines.0.transaction.state', 'confirmed');
});

it('creates a draft from explicit transaction ids', function () {
    $this->actingAs($this->fixture->user, 'merchant');
    $ids = array_slice($this->fixture->transactionIds(), 0, 2);

    $this->postJson('/api/merchant/settlements', ['ids' => $ids])
        ->assertCreated()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.amount_due_laari', 2750 + 1375);

    // A transaction already claimed by the open draft is rejected.
    $this->postJson('/api/merchant/settlements', ['ids' => [$ids[0]]])
        ->assertUnprocessable();

    // Neither ids nor settle_all is a validation error.
    $this->postJson('/api/merchant/settlements', [])
        ->assertUnprocessable();
});

it('settles from the wallet over HTTP and shows the movements', function () {
    app(WalletFunding::class)->recordTopUp($this->fixture->merchant, Laari::of(20000), 'BML-TOPUP-9');

    $this->actingAs($this->fixture->user, 'merchant');

    $settlementId = $this->postJson('/api/merchant/settlements', ['settle_all' => true])->json('data.id');
    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")->assertOk();

    $this->postJson("/api/merchant/settlements/{$settlementId}/wallet-settle")
        ->assertOk()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.funding_method', 'wallet');

    $this->getJson('/api/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance_laari', 20000 - 11825)
        ->assertJsonPath('data.balance_mvr', '81.75')
        ->assertJsonCount(2, 'data.transactions');
});

it('rejects wallet settlement the balance cannot cover', function () {
    $this->actingAs($this->fixture->user, 'merchant');

    $settlementId = $this->postJson('/api/merchant/settlements', ['settle_all' => true])->json('data.id');
    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")->assertOk();

    $this->postJson("/api/merchant/settlements/{$settlementId}/wallet-settle")
        ->assertUnprocessable();
});

it('never leaks another merchant\'s settlement', function () {
    $this->actingAs($this->fixture->user, 'merchant');
    $settlementId = $this->postJson('/api/merchant/settlements', ['settle_all' => true])->json('data.id');

    $otherMerchant = Merchant::factory()->create();
    $intruder = MerchantUser::factory()->for($otherMerchant)->owner()->create();

    $this->actingAs($intruder, 'merchant');

    // Merchant B sees a plain 404 on every route naming merchant A's batch.
    $this->getJson("/api/merchant/settlements/{$settlementId}")->assertNotFound();
    $this->postJson("/api/merchant/settlements/{$settlementId}/submit")->assertNotFound();
    $this->postJson("/api/merchant/settlements/{$settlementId}/wallet-settle")->assertNotFound();

    // And B's own listing stays empty.
    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('requires the right guard on every route', function () {
    $this->getJson('/api/merchant/settlements')->assertUnauthorized();
    $this->getJson('/api/merchant/outstanding')->assertUnauthorized();
    $this->getJson('/api/merchant/wallet')->assertUnauthorized();
    $this->getJson('/api/admin/settlements')->assertUnauthorized();

    // A merchant token does not open admin routes.
    $this->actingAs($this->fixture->user, 'merchant');
    $this->getJson('/api/admin/settlements')->assertUnauthorized();
});
