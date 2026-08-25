<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletTopUps;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantMarketplaceProfile;
use App\Models\PlatformBankAccount;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The attention panel: six queues, one number each, and every number the
 * SAME predicate the queue's own endpoint lists on. Built here out of real
 * rows through real paths, then counted.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    Queue::fake();

    $this->admin = AdminUser::factory()->create(['role' => 'admin']);

    $this->account = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function dashboardAttention(): array
{
    return test()->actingAs(test()->admin, 'admin')
        ->getJson('/api/admin/dashboard')
        ->assertOk()
        ->json('attention');
}

it('counts nothing when nothing is waiting', function (): void {
    expect(dashboardAttention())->toBe([
        'settlements_payment_review' => 0,
        'wallet_top_ups_pending' => 0,
        'store_reviews_pending' => 0,
        'change_requests_pending' => 0,
        'holds_open' => 0,
        'total' => 0,
    ]);
});

it('counts each queue from its own predicate', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $builder = app(SettlementBuilder::class);

    // A settlement payment awaiting review: the merchant says they paid, and
    // nothing has matched it.
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[0]->id]));
    app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(2_750), 'BML-ATTENTION-1');

    // A wallet top-up claim, same shape, other table.
    app(WalletTopUps::class)->claim(
        $fixture->merchant,
        $fixture->user,
        Laari::of(50_000),
        $this->account->id,
        '901901901',
        Slips::jpeg(),
    );

    // A store that has finished its wizard and is waiting to go live.
    Merchant::factory()->create(['status' => 'pending_review']);

    // A live store asking to change what a shopper reads.
    MerchantChangeRequest::query()->create([
        'merchant_id' => $fixture->merchant->id,
        'kind' => 'profile',
        'payload' => ['name' => 'A New Name'],
        'snapshot' => ['name' => $fixture->merchant->name],
        'status' => MerchantChangeRequest::PENDING,
        'submitted_by' => $fixture->user->id,
    ]);
    // ...and one already decided, which is not waiting on anybody.
    MerchantChangeRequest::query()->create([
        'merchant_id' => $fixture->merchant->id,
        'kind' => 'profile',
        'payload' => ['name' => 'Older Name'],
        'status' => MerchantChangeRequest::APPROVED,
        'submitted_by' => $fixture->user->id,
        'reviewed_by' => AdminUser::factory()->create()->id,
        'reviewed_at' => now(),
    ]);

    // A sale under fraud review — the one queue where a customer is waiting
    // on the answer too.
    app(TransitionService::class)->hold($fixture->transactions[3], Actor::admin($this->admin->id), 'velocity');

    expect(dashboardAttention())->toBe([
        'settlements_payment_review' => 1,
        'wallet_top_ups_pending' => 1,
        'store_reviews_pending' => 1,
        'change_requests_pending' => 1,
        'holds_open' => 1,
        'total' => 5,
    ]);
});

it('shows the KYB queue only while the marketplace is switched on', function (): void {
    MerchantMarketplaceProfile::factory()->create(['state' => 'pending_kyb', 'approved_at' => null]);
    MerchantMarketplaceProfile::factory()->create(['state' => 'active']);

    // OFF: the key is absent, not zero. PLAN-marketplace.md §10 — "off means
    // every surface hides it", and a permanent "0 applications" tile is one.
    $off = dashboardAttention();

    expect($off)->not->toHaveKey('marketplace_kyb_pending')
        ->and($off['total'])->toBe(0);

    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $on = dashboardAttention();

    expect($on['marketplace_kyb_pending'])->toBe(1)
        ->and($on['total'])->toBe(1);
});

it('stops counting a queue item once it is decided', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[0]->id]));
    $payment = app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(2_750), 'BML-ATTENTION-2');

    expect(dashboardAttention()['settlements_payment_review'])->toBe(1);

    app(SettlementAllocator::class)->matchPayment($payment, $this->admin);

    expect(dashboardAttention()['settlements_payment_review'])->toBe(0);
});

/*
 * THE TILE IS THE LIST. §7 lets a merchant put a SECOND receipt against a
 * batch whose first one is still pending, so counting receipts made the tile
 * say 2 while /settlements?state=payment_review — the screen it opens —
 * showed one row. The count is the list's own predicate for that reason.
 */
it('counts a batch once however many receipts are waiting on it', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[0]->id]));

    app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(1_000), 'BML-TWO-A');
    app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(1_750), 'BML-TWO-B');

    expect(SettlementPayment::query()->where('state', 'pending')->count())->toBe(2)
        ->and(Settlement::query()->where('state', SettlementState::PaymentReview->value)->count())->toBe(1)
        // The screen behind the tile lists BATCHES, so the tile counts them.
        ->and(dashboardAttention()['settlements_payment_review'])->toBe(1);
});

/*
 * The nav badges read this instead of fetching four LISTS to pull one scalar
 * off each. It has to be the same six numbers, or a badge and the tile it
 * links to disagree — which is the whole thing AttentionQueues prevents.
 */
it('serves the same counts on their own for the nav badges', function (): void {
    $fixture = SettlementFixture::payableBatch();
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[0]->id]));
    app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of(2_750), 'BML-BADGE-1');
    Merchant::factory()->create(['status' => 'pending_review']);

    $badges = $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/dashboard/attention')
        ->assertOk()
        ->json();

    expect($badges)->toBe(dashboardAttention())
        ->and($badges['settlements_payment_review'])->toBe(1)
        ->and($badges['store_reviews_pending'])->toBe(1);
});

it('refuses the badge counts to anyone who is not signed in', function (): void {
    $this->getJson('/api/admin/dashboard/attention')->assertUnauthorized();
});
