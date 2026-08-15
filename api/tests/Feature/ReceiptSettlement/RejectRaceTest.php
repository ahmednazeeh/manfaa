<?php

declare(strict_types=1);

use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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

function submitRaceReceipt(int $amountLaari, string $bankRef): int
{
    return test()->actingAs(test()->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => $amountLaari,
            'bank_ref' => $bankRef,
            'slip' => Slips::jpeg(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'payment_review')
        ->json('data.id');
}

/**
 * The reject window: a `FOR UPDATE` over the batch's payments locks the rows
 * it returns; it does not stop an INSERT. A second receipt that commits
 * between reject()'s first read and its settlement row lock must still be
 * rejected — a pending payment left on a CANCELLED batch is unmatchable
 * (cancelled is not a matchable state), un-rejectable (the batch has left
 * payment_review) and its bank reference is burnt by the partial unique
 * index, which exempts only rejected rows. That is real money in the
 * platform's account with no in-app path to book it or reclaim the transfer.
 *
 * The interleaving is forced deterministically: the model-retrieved hook
 * fires while reject() is hydrating the payment set, exactly where the
 * merchant's concurrent commit would land.
 */
it('rejects a receipt that lands after the payment set was first read', function () {
    $settlementId = submitRaceReceipt(5_000, 'BML-RACE-1');
    $settlement = Settlement::query()->findOrFail($settlementId);

    $landed = false;

    Event::listen('eloquent.retrieved: '.SettlementPayment::class, function () use (&$landed, $settlement): void {
        if ($landed) {
            return;
        }

        $landed = true;

        $settlement->payments()->create([
            'merchant_id' => $settlement->merchant_id,
            'amount_laari' => 6_825,
            'currency' => 'MVR',
            'method' => 'bank',
            'bank_ref' => 'BML-RACE-2',
            'state' => 'pending',
        ]);
    });

    app(SettlementBuilder::class)->reject($settlement, $this->admin, 'No transfer with these references arrived.');

    expect($landed)->toBeTrue();

    $payments = SettlementPayment::query()->orderBy('id')->get();

    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('state')->all())->toBe(['rejected', 'rejected'])
        ->and($payments->pluck('bank_ref')->all())->toBe(['BML-RACE-1', 'BML-RACE-2'])
        ->and($payments->every(fn (SettlementPayment $payment): bool => $payment->rejected_by === $this->admin->id))->toBeTrue()
        ->and($payments->every(fn (SettlementPayment $payment): bool => $payment->rejected_at !== null))->toBeTrue();

    expect(Settlement::query()->findOrFail($settlementId)->state)->toBe(SettlementState::Cancelled);
});

it('leaves the raced receipt reference re-usable, so the transfer is never stranded', function () {
    $settlementId = submitRaceReceipt(5_000, 'BML-STRAND-1');
    $settlement = Settlement::query()->findOrFail($settlementId);

    $landed = false;

    Event::listen('eloquent.retrieved: '.SettlementPayment::class, function () use (&$landed, $settlement): void {
        if ($landed) {
            return;
        }

        $landed = true;

        $settlement->payments()->create([
            'merchant_id' => $settlement->merchant_id,
            'amount_laari' => 11_825,
            'currency' => 'MVR',
            'method' => 'bank',
            'bank_ref' => 'BML-STRAND-2',
            'state' => 'pending',
        ]);
    });

    app(SettlementBuilder::class)->reject($settlement, $this->admin, 'Slip unreadable.');

    Event::forget('eloquent.retrieved: '.SettlementPayment::class);

    // The whole point of rejecting every payment: the partial unique index
    // exempts rejected rows, so the merchant can quote the SAME real transfer
    // on the fresh batch the release makes possible.
    $retryId = submitRaceReceipt(11_825, 'BML-STRAND-2');

    expect($retryId)->not->toBe($settlementId)
        ->and(Settlement::query()->findOrFail($retryId)->state)->toBe(SettlementState::PaymentReview)
        ->and(Settlement::query()->findOrFail($retryId)->lines()->count())->toBe(4);
});
