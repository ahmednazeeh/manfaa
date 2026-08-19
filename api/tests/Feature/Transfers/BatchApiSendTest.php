<?php

declare(strict_types=1);

use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutItemState;
use App\Domain\Transfers\BatchApiSender;
use App\Domain\Transfers\BatchNotSendableException;
use App\Domain\Transfers\TransferProfileRef;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\TransferProfile;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Paying a whole batch through the bank API — the third road, beside the
 * exported sheet and the per-row Mark paid.
 *
 * The rule under test throughout: a refusal is recorded and the pass moves
 * on. It is never retried, and it never stops the batch.
 */

beforeEach(function (): void {
    // Paying an item posts to the ledger, the same way an uploaded sheet does.
    $this->seed(LedgerAccountSeeder::class);

    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501480029671000',
        'active' => true,
        'is_default' => true,
    ]);
});

/** An approved batch with `$count` pending rows. */
function approvedBatch(int $count = 3): PayoutBatch
{
    $batch = PayoutBatch::query()->create([
        'reference' => 'PB-20260819',
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'cutoff_at' => now()->subDay(),
        'state' => PayoutBatchState::Approved,
        'total_laari' => 10000 * $count,
        'customer_count' => $count,
    ]);

    for ($i = 0; $i < $count; $i++) {
        PayoutItem::query()->create([
            'batch_id' => $batch->id,
            'customer_id' => Customer::factory()->create()->id,
            'amount_laari' => 10000,
            'idempotency_key' => 'MANFAA-PO-'.(1000 + $i),
            'bank' => 'bml',
            'account' => '773000075792'.$i,
            'account_name' => 'Customer '.$i,
            'state' => PayoutItemState::Pending,
        ]);
    }

    return $batch;
}

it('pays every row and marks the batch complete', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    $batch = approvedBatch(3);
    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->sent)->toBe(3);
    expect($batch->refresh()->state)->toBe(PayoutBatchState::Completed);
    expect($batch->items()->where('state', PayoutItemState::Paid)->count())->toBe(3);
    // Not exported_at: no file was ever made.
    expect($batch->api_sent_at)->not->toBeNull();
    expect($batch->exported_at)->toBeNull();
});

it('sends each row its own idempotency key as internal_ref', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    app(BatchApiSender::class)->sendCustomerBatch(approvedBatch(2), TransferProfileRef::resolve());

    // The key that makes a re-run safe. Without it a worker that died
    // halfway would pay the first rows twice.
    Http::assertSent(fn ($request) => ($request->data()['internal_ref'] ?? null) === 'MANFAA-PO-1000');
    Http::assertSent(fn ($request) => ($request->data()['internal_ref'] ?? null) === 'MANFAA-PO-1001');
});

it('moves on past a refusal instead of retrying it', function (): void {
    // The owner's rule, stated exactly: "When api returns transfer failed,
    // dont retry and instead move on to next transfer."
    Http::fake(['*' => Http::sequence()
        ->push(['status' => 'success', 'trx_id' => 'TRX-1'])
        ->push(['error_code' => 'invalid_account', 'error' => 'No such account'], 400)
        ->push(['status' => 'success', 'trx_id' => 'TRX-3'])]);

    $batch = approvedBatch(3);
    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->sent)->toBe(2);
    expect($summary->failed)->toBe(1);

    // Three calls, not four: the failure was recorded, never repeated.
    Http::assertSentCount(3);

    // The third row was paid, which is the whole point — one bad account
    // number must not stop everybody else being paid.
    $items = $batch->items()->orderBy('id')->get();
    expect($items[0]->state)->toBe(PayoutItemState::Paid);
    expect($items[1]->state)->toBe(PayoutItemState::Failed);
    expect($items[2]->state)->toBe(PayoutItemState::Paid);

    expect($batch->refresh()->state)->toBe(PayoutBatchState::PartiallyFailed);
});

it('leaves an ambiguous refusal pending for a person', function (): void {
    // A timeout or a 500 does not prove the money stayed put. Marking it
    // failed would re-queue cashback that may already have been paid.
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    $batch = approvedBatch(1);
    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->needsReview)->toBe(1);

    $item = $batch->items()->sole();
    expect($item->state)->toBe(PayoutItemState::Pending);
    expect($item->failure_reason)->toContain('Needs review');
    // Still pending, so the batch has not concluded either way.
    expect($batch->refresh()->state)->toBe(PayoutBatchState::Processing);
});

it('parks a transfer waiting on a second approver without paying it', function (): void {
    // Dual control answers 200 with pending_approval and an approvals-queue
    // record id. That money is alive, not refused.
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_9'])]);

    $batch = approvedBatch(1);
    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->parked)->toBe(1);

    $item = $batch->items()->sole();
    // `sent`, so a later pass skips it — re-sending a parked transfer pays
    // twice.
    expect($item->state)->toBe(PayoutItemState::Sent);
    expect($item->approval_id)->toBe('rec_9');
    // An approvals id is NOT a bank reference and is never filed as one.
    expect($item->bank_reference)->toBeNull();
});

it('does not pay a parked row again on a second pass', function (): void {
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_9'])]);

    $batch = approvedBatch(1);
    app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    Http::assertSentCount(1);

    app(BatchApiSender::class)->sendCustomerBatch($batch->refresh(), TransferProfileRef::resolve());

    // Still one. The second pass swept over it.
    Http::assertSentCount(1);
});

it('adopts the reference of a transfer the bank already made', function (): void {
    // The 409 path: this internal_ref has been seen. Adopting the existing
    // reference rather than sending again is the difference between
    // idempotent and double-paying.
    Http::fake(['*' => Http::response([
        'existing' => ['status' => 'success', 'trx_id' => 'TRX-ORIGINAL'],
    ], 409)]);

    $batch = approvedBatch(1);
    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->sent)->toBe(1);
    expect($batch->items()->sole()->bank_reference)->toBe('TRX-ORIGINAL');
});

it('skips a row that has already been settled by hand', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    $batch = approvedBatch(2);
    $batch->items()->orderBy('id')->first()->forceFill(['state' => PayoutItemState::Paid])->save();

    $summary = app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve());

    expect($summary->skipped)->toBe(1);
    expect($summary->sent)->toBe(1);
    Http::assertSentCount(1);
});

it('refuses a draft batch', function (): void {
    $batch = approvedBatch(1);
    $batch->forceFill(['state' => PayoutBatchState::Draft])->save();

    expect(fn () => app(BatchApiSender::class)->sendCustomerBatch($batch, TransferProfileRef::resolve()))
        ->toThrow(BatchNotSendableException::class);
});

it('pays from our account at the payee\'s own bank when we have one', function (): void {
    // The payout rows are all BML payees. Given a BML profile, the transfer
    // should leave from it rather than crossing banks.
    $this->profile->forceFill(['bank' => 'mib'])->save();

    TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        'bank' => 'bml',
        'active' => true,
    ]);

    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    app(BatchApiSender::class)->sendCustomerBatch(approvedBatch(1), TransferProfileRef::resolve());

    Http::assertSent(fn ($request) => str_contains($request->url(), '/bml/transfer'));
});

it('honours an explicitly chosen profile for every row', function (): void {
    // An operator picking a profile is a decision, not a default — it is
    // not second-guessed per payee.
    TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        'bank' => 'bml',
        'active' => true,
    ]);

    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    app(BatchApiSender::class)->sendCustomerBatch(
        approvedBatch(1),
        TransferProfileRef::resolve($this->profile->id),
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/faisanet4/transfer'));
});

/*
 * The settlement run — what the platform owes shops. Same three rules,
 * different table.
 */

function approvedSettlement(int $count = 2): MerchantPayoutBatch
{
    $batch = MerchantPayoutBatch::query()->create([
        'reference' => 'MS-20260819',
        'cutoff_at' => now()->subDay(),
        'state' => 'approved',
        'total_laari' => 50000 * $count,
        'merchant_count' => $count,
        'excluded_laari' => 0,
        'excluded_count' => 0,
    ]);

    for ($i = 0; $i < $count; $i++) {
        MerchantPayoutItem::query()->create([
            'batch_id' => $batch->id,
            'merchant_id' => Merchant::factory()->create()->id,
            'amount_laari' => 50000,
            'merchant_name' => 'Shop '.$i,
            'internal_ref' => 'MANFAA-MS-'.(2000 + $i),
            'bank' => 'bml',
            'account' => '773000012345'.$i,
            'account_name' => 'Shop '.$i,
            'state' => 'pending',
        ]);
    }

    return $batch;
}

it('pays a settlement run and completes it', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-M1'])]);

    $batch = approvedSettlement(2);
    $summary = app(BatchApiSender::class)->sendMerchantBatch($batch, TransferProfileRef::resolve());

    expect($summary->sent)->toBe(2);
    expect($batch->refresh()->state)->toBe('completed');
    expect($batch->items()->where('state', 'sent')->count())->toBe(2);
});

it('moves past a refused settlement row without retrying', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['error_code' => 'invalid_account', 'error' => 'No such account'], 400)
        ->push(['status' => 'success', 'trx_id' => 'TRX-M2'])]);

    $batch = approvedSettlement(2);
    $summary = app(BatchApiSender::class)->sendMerchantBatch($batch, TransferProfileRef::resolve());

    expect($summary->failed)->toBe(1);
    expect($summary->sent)->toBe(1);
    Http::assertSentCount(2);

    $items = $batch->items()->orderBy('id')->get();
    expect($items[0]->state)->toBe('failed');
    expect($items[1]->state)->toBe('sent');
    // Not every row is paid, so the run stays open.
    expect($batch->refresh()->state)->toBe('processing');
});

it('parks a settlement waiting on a second approver in its own state', function (): void {
    Http::fake(['*' => Http::response(['status' => 'pending_approval', 'approval_id' => 'rec_11'])]);

    $batch = approvedSettlement(1);
    app(BatchApiSender::class)->sendMerchantBatch($batch, TransferProfileRef::resolve());

    $item = $batch->items()->sole();
    // Deliberately not `sent`: the shop has not been paid, and the
    // settlements screen must not say it has.
    expect($item->state)->toBe('pending_approval');
    expect($item->approval_id)->toBe('rec_11');
    expect($item->trx_id)->toBeNull();
});

it('sends each settlement row its own internal ref', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-M1'])]);

    app(BatchApiSender::class)->sendMerchantBatch(approvedSettlement(2), TransferProfileRef::resolve());

    // The same string the sheet is matched on: one reference identifies a
    // transfer in our table, the bank's, and the paper in between.
    Http::assertSent(fn ($request) => ($request->data()['internal_ref'] ?? null) === 'MANFAA-MS-2000');
    Http::assertSent(fn ($request) => ($request->data()['internal_ref'] ?? null) === 'MANFAA-MS-2001');
});

it('refuses a draft settlement run', function (): void {
    $batch = approvedSettlement(1);
    $batch->forceFill(['state' => 'draft'])->save();

    expect(fn () => app(BatchApiSender::class)->sendMerchantBatch($batch, TransferProfileRef::resolve()))
        ->toThrow(BatchNotSendableException::class);
});
