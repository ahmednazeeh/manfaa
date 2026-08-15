<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Builds the §7 situation the zero-due rule exists for: a confirmed reward
 * is refunded (→ pending credit memo of 2,750) and a fresh sale of exactly
 * the same due is put on the clock. Returns the fresh transaction's id.
 */
function creditNettedSetup(): int
{
    $builder = app(SettlementBuilder::class);
    $allocator = app(SettlementAllocator::class);

    $t0 = test()->fixture->transactions[0];
    $own = $builder->submit($builder->createDraft(test()->fixture->merchant, [$t0->id]));
    $payment = $allocator->recordBankPayment($own, Laari::of(2_750), 'BML-NETTED-PAID');
    $allocator->matchPayment($payment, test()->admin);

    app(ReversalService::class)->reverse($t0, Actor::system(), 'customer_refund', now()->toImmutable());

    $fresh = app(ManualCreditService::class)->credit(
        test()->fixture->merchant,
        test()->fixture->user,
        test()->fixture->customer->customer_code,
        'INV-NETTED-0',
        Laari::of(100_000),
        null,
        now()->subHour()->toImmutable(),
    );

    app(TransitionService::class)->makePayable($fresh, Actor::system());

    return $fresh->id;
}

it('refuses a receipt for a batch the credits already cover, and creates nothing', function () {
    $freshId = creditNettedSetup();

    $this->actingAs($this->fixture->user, 'merchant');

    // The preview is honest about it first: nothing is due.
    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$freshId)
        ->assertOk()
        ->assertJsonPath('data.line_total_laari', 2_750)
        ->assertJsonPath('data.credit_applied_laari', 2_750)
        ->assertJsonPath('data.amount_due_laari', 0);

    // A receipt claims a transfer that cannot have happened — refused, and
    // the draft it would have built rolls back with it.
    $settlementsBefore = Settlement::query()->count();

    $this->post('/api/merchant/settlements', [
        'transaction_ids' => [$freshId],
        'amount' => 2_750,
        'bank_ref' => 'BML-NETTED-BOGUS',
        'slip' => Slips::jpeg(),
    ])->assertUnprocessable();

    expect(Settlement::query()->count())->toBe($settlementsBefore)
        ->and(SettlementPayment::query()->where('bank_ref', 'BML-NETTED-BOGUS')->exists())->toBeFalse()
        ->and(Storage::disk('slips')->allFiles())->toBe([]);
});

it('settles a fully credit-netted batch through the wallet route without drawing a laari', function () {
    $freshId = creditNettedSetup();

    $this->actingAs($this->fixture->user, 'merchant');

    // No wallet balance at all — and none is needed: the applied credit IS
    // the funding, so the batch settles at submit (§7, PLAN §6 zero-due).
    $this->postJson('/api/merchant/settlements/wallet', ['transaction_ids' => [$freshId]])
        ->assertCreated()
        ->assertJsonPath('data.state', 'settled')
        ->assertJsonPath('data.amount_due_laari', 0)
        ->assertJsonPath('data.amount_received_laari', 0);

    expect(Transaction::query()->findOrFail($freshId)->state)
        ->toBe(TransactionState::Confirmed)
        ->and($this->fixture->merchant->wallet()->first()?->balance_laari ?? 0)->toBe(0);
});
