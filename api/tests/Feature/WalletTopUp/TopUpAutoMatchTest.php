<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\DuplicateBankRefException;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\WalletFunding;
use App\Domain\Settlement\WalletTopUps;
use App\Domain\Transfers\BankCreditClaim;
use App\Domain\Transfers\BankHistoryClient;
use App\Domain\Transfers\SettlementPaymentVerifier;
use App\Domain\Transfers\WalletTopUpVerifier;
use App\Jobs\PollWalletTopUp;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Matching a wallet top-up against the bank's own history — the sibling of
 * SettlementAutoMatchTest, walking the same ladder. What differs is what a
 * match FUNDS: the wallet is credited, exactly once, through the one path
 * the admin's manual match also takes.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    // The claim dispatches its watch jobs after commit; the verifier is
    // driven by hand here, so keep the queue out of it.
    Queue::fake();
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    $this->account = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $this->profile->id,
    ]);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
        'verify_min_score' => 60,
    ])->save();

    $this->merchant = Merchant::factory()->create([
        'name' => 'Agromart',
        'bank_account_name' => 'Ahmed Nazeeh',
        'contact_phone' => '+9607771234',
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->balances = new Balances;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function claimTopUp(int $laari, ?string $bankRef, ?int $accountId = null): WalletTopUp
{
    return app(WalletTopUps::class)->claim(
        test()->merchant,
        test()->owner,
        Laari::of($laari),
        $accountId ?? test()->account->id,
        $bankRef,
        Slips::jpeg(),
    );
}

function bankRow(string $name, int $laari, string $reference = '804802801', array $extra = []): void
{
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => [array_merge([
        'trxNumber2' => $reference,
        'baseAmount' => $laari / 100,
        'absAmount' => $laari / 100,
        'benefName' => $name,
        'trxDate' => '2026-08-24 10:00:00',
    ], $extra)]])]);
}

/** Two incoming credits in one page of history, in this order. */
function bankRows(array $rows): void
{
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => array_map(
        static fn (array $row): array => array_merge([
            'baseAmount' => $row['laari'] / 100,
            'absAmount' => $row['laari'] / 100,
            'benefName' => $row['name'] ?? 'WHOEVER',
            'trxNumber2' => $row['reference'],
            'trxDate' => '2026-08-24 10:00:00',
        ], $row['extra'] ?? []),
        $rows,
    )])]);
}

/** The merchant's wallet balance, or nothing where no wallet exists yet. */
function topUpWalletBalance(): int
{
    $wallet = test()->merchant->wallet()->first();

    return $wallet === null ? 0 : (int) $wallet->balance_laari;
}

function topUpJournals(): int
{
    return DB::table('ledger_journals')->where('description', 'Merchant wallet top-up')->count();
}

it('matches on the reference the merchant typed and credits the wallet exactly once', function (): void {
    $topUp = claimTopUp(20000, '804802801');

    bankRow('SOME UNRELATED LABEL', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $topUp->refresh();
    expect($topUp->state)->toBe('matched')
        ->and($topUp->auto_matched)->toBeTrue()
        ->and($topUp->matched_by_rule)->toBe('reference')
        ->and($topUp->matched_trx_id)->toBe('804802801')
        ->and($topUp->matched_payer_name)->toBe('SOME UNRELATED LABEL')
        ->and($topUp->matched_by)->toBeNull()
        ->and($topUp->matched_at)->not->toBeNull()
        ->and($topUp->poll_until)->toBeNull();

    // The money, through WalletFunding::recordTopUp: one movement keyed on
    // the merchant's own reference, one journal.
    $wallet = $this->merchant->wallet()->sole();
    $movement = $wallet->transactions()->sole();

    expect($wallet->balance_laari)->toBe(20000)
        ->and($movement->type)->toBe('top_up')
        ->and($movement->bank_ref)->toBe('804802801')
        ->and($movement->description)->toContain("Wallet top-up #{$topUp->id}")
        ->and($topUp->wallet_transaction_id)->toBe($movement->id)
        ->and(topUpJournals())->toBe(1)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(20000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();

    // Told: SMS to the store's own number (see the notification test below).
    Queue::assertPushed(SendCustomerSms::class, 1);
});

it('a second look cannot credit the wallet again', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    // Same credit, same claim, asked again — a duplicate poll, a retried
    // worker. Already matched, so nothing happens.
    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeFalse();
    (new PollWalletTopUp($topUp->id))->handle(app(WalletTopUpVerifier::class));

    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(20000)
        ->and($this->merchant->wallet()->sole()->transactions()->count())->toBe(1)
        ->and(topUpJournals())->toBe(1);

    // And a SECOND claim naming the same credit finds it already spent.
    $again = claimTopUp(20000, null);
    expect(app(WalletTopUpVerifier::class)->attempt($again))->toBeFalse();
    expect($again->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(20000);
});

it('never credits on the payer\'s name alone — the merchant picks the amount, so a name is not evidence', function (): void {
    // The registered account name matches the payer exactly and the amount
    // matches to the laari. For a SETTLEMENT that is a match; for a top-up
    // the merchant chose both, so it waits for a person.
    $topUp = claimTopUp(20000, null);

    bankRow('AHMED NAZEEH', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending')
        ->and($topUp->matched_by_rule)->toBeNull();
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('never credits a stranger\'s transfer named on a merchant-authored slip', function (): void {
    // A one-token account name that any payer containing it would score
    // 100 against, and a slip listing common names — the two oracles the
    // name rungs would have been. Neither may credit a stranger's money.
    $this->merchant->forceFill(['bank_account_name' => 'Ahmed'])->save();

    $topUp = claimTopUp(50000, null);
    $topUp->forceFill([
        'receipt_text' => 'AHMED MOHAMED ALI HASSAN IBRAHIM HUSSAIN MARIYAM SHIFA AISHATH FATHIMATH',
    ])->save();

    bankRow('MARIYAM SHIFA', 50000, '804802801');
    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeFalse();

    bankRow('AHMED SHAREEF', 50000, '804802802');
    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeFalse();

    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('matches on the transaction number printed on the receipt', function (): void {
    $topUp = claimTopUp(20000, null);
    $topUp->forceFill([
        'receipt_text' => 'CLEVIDEN TRANSACTION# 90863389 FROM INTERBRIDGE PVT LTD MVR 200.00',
    ])->save();

    bankRow('INTERBRIDGE', 20000, '90863389');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeTrue();
    expect($topUp->refresh()->matched_by_rule)->toBe('receipt_reference');
});

it('refuses a credit from a different payer with no reference', function (): void {
    $topUp = claimTopUp(20000, null);

    bankRow('MARIYAM SHIFA', 20000);

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('credits the BANK\'s figure when it is not the one the merchant typed', function (): void {
    // THE PRODUCTION CASE (owner, 2026-08-25, wallet top-up #2): the
    // merchant typed MVR 200.00, the bank credited MVR 199.99. The old
    // equality gate parked a real transfer in the admin queue over that.
    $topUp = claimTopUp(20000, '804802801');

    bankRow('WHOEVER', 19999, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $topUp->refresh();

    expect($topUp->state)->toBe('matched')
        // The claim, exactly as typed, forever.
        ->and($topUp->amount_laari)->toBe(20000)
        // The fact, off the statement row.
        ->and($topUp->received_laari)->toBe(19999)
        ->and($topUp->amountDiffers())->toBeTrue();

    // The MONEY is the bank's figure, not the claim.
    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(19999)
        ->and($this->merchant->wallet()->sole()->transactions()->sole()->amount_laari)->toBe(19999);
});

it('THE PRODUCTION ROW: claimed MVR 20.00, MVR 10.00 arrived, MVR 10.00 credited', function (): void {
    // Wallet top-up #2, 2026-08-25. The merchant typed MVR 20.00; the slip
    // they uploaded and BML's own statement both say MVR 10.00, reference
    // BLAZ204399156496. The verifier polled the whole window, matched
    // nothing, and parked a perfectly good transfer for a person to sort
    // out. This is that row, and it must now go through.
    app(PlatformConfig::class)->set('wallet_top_up_min_laari', 100);

    $topUp = claimTopUp(2000, 'BLAZ204399156496');

    bankRow('AGROMART PVT LTD', 1000, 'BLAZ204399156496');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $topUp->refresh();

    expect($topUp->state)->toBe('matched')
        // The EVIDENCE decided it — the reference, unchanged and unrelaxed.
        ->and($topUp->matched_by_rule)->toBe('reference')
        // The CLAIM, still readable, still exactly what they typed.
        ->and($topUp->amount_laari)->toBe(2000)
        // The FACT, off the statement row.
        ->and($topUp->received_laari)->toBe(1000)
        ->and($topUp->amountDiffers())->toBeTrue();

    // MVR 10.00 is what the bank sent, so MVR 10.00 is what the wallet
    // holds — and the ledger balances on that figure, not on the claim.
    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(1000)
        ->and($this->merchant->wallet()->sole()->transactions()->sole()->amount_laari)->toBe(1000)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(1000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('credits a top-up that arrived UNDER the platform minimum rather than holding it hostage', function (): void {
    // The minimum gates what may be CLAIMED, at claim time. Money that
    // genuinely arrived is the merchant's, and refusing to credit it over
    // their own typo is the failure this round exists to end.
    app(PlatformConfig::class)->set('wallet_top_up_min_laari', 10000);

    $topUp = claimTopUp(10000, '804802801');

    bankRow('WHOEVER', 500, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();
    expect($topUp->refresh()->received_laari)->toBe(500)
        ->and($this->merchant->wallet()->sole()->balance_laari)->toBe(500);
});

it('credits MORE than was claimed when more than was claimed arrived', function (): void {
    // The mirror. The bank is the fact in both directions — a merchant who
    // undertyped is not quietly short-changed either.
    $topUp = claimTopUp(10000, '804802801');

    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $topUp->refresh();

    expect($topUp->amount_laari)->toBe(10000)
        ->and($topUp->received_laari)->toBe(20000);

    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(20000)
        ->and($this->balances->naturalBalance(AccountCode::MerchantWalletBalance))->toBe(20000)
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('never credits a row that says money came IN but carries nothing', function (): void {
    // BML's feed is the shape where this is expressible: direction is a
    // separate `minus` flag rather than the amount's sign, so a row can be
    // incoming AND zero. Removing the equality gate must not let one
    // through — a credit of nothing is not a credit.
    $bml = TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        'from_account' => null,
        'upstream_profile' => 'CLEVIDEN',
        'active' => true,
    ]);

    $account = PlatformBankAccount::query()->create([
        'bank_name' => 'bml',
        'account_no' => '7730000757923',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => false,
        'active' => true,
        'verify_profile_id' => $bml->id,
    ]);

    $topUp = claimTopUp(20000, 'BLAZ204399156496', $account->id);

    Http::fake(['*/bml/history*' => Http::response([[
        'id' => 'FT26235BDLZB',
        'narrative2' => 'BLAZ204399156496',
        'narrative3' => 'AGROMART',
        'amount' => 0,
        'minus' => false,
        'bookingDate' => '2026-08-25T00:00:00+05:00',
    ]])]);

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->exists())->toBeFalse();
});

it('still refuses a typed reference too short to be evidence', function (): void {
    // TransferEvidence's 6-character floor, UNCHANGED. The amount used to
    // be a second lock on this door; now the reference is the only one, so
    // the floor matters more than it did, not less.
    $topUp = claimTopUp(20000, '80480');

    bankRow('WHOEVER', 20000, '80480');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending')
        ->and($this->merchant->wallet()->exists())->toBeFalse();
});

it('still refuses a receipt needle too short to be evidence', function (): void {
    // The 5-character floor on TransferEvidence::receiptMentions, likewise
    // untouched.
    $topUp = claimTopUp(20000, null);
    $topUp->forceFill(['receipt_text' => 'CLEVIDEN TRANSACTION# 8048 MVR 200.00'])->save();

    bankRow('WHOEVER', 20000, '8048');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
});

it('ignores money going the wrong way', function (): void {
    $topUp = claimTopUp(20000, '804802801');

    bankRow('WHOEVER', 20000, '804802801', ['baseAmount' => -200]);

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
});

it('never spends a credit already claimed by a settlement payment', function (): void {
    // Another merchant's settlement, matched against this very credit.
    $fixture = SettlementFixture::payableBatch();
    Carbon::setTestNow();

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($fixture->merchant);
    $builder->submit($settlement);
    $settlement->refresh()->forceFill(['platform_bank_account_id' => $this->account->id])->save();

    $due = (int) $settlement->amount_due_laari;
    $payment = app(SettlementAllocator::class)->recordBankPayment($settlement->refresh(), Laari::of($due), '804802801');

    bankRow('WHOEVER', $due, '804802801');
    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    // Now a top-up for exactly that amount, quoting that reference. The
    // credit is spent — across tables — and must not fund the wallet.
    $topUp = claimTopUp($due, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('never spends a credit a settlement payment matched BY HAND already took', function (): void {
    // Same transfer, reconciled by a person: no bank row was read, so no
    // matched_trx_* was written. The payment's own reference must still
    // count as spent, or the merchant re-claims the transfer as a top-up.
    $fixture = SettlementFixture::payableBatch();
    Carbon::setTestNow();

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->createDraft($fixture->merchant);
    $builder->submit($settlement);

    $due = (int) $settlement->refresh()->amount_due_laari;
    $payment = app(SettlementAllocator::class)->recordBankPayment($settlement, Laari::of($due), 'REF-SAME');
    app(SettlementAllocator::class)->matchPayment($payment, AdminUser::factory()->create());

    expect($payment->refresh()->matched_trx_refs)->toBe(['REF-SAME']);

    // The same merchant, typing the same reference: refused at the form.
    expect(fn () => app(WalletTopUps::class)->claim(
        $fixture->merchant,
        $fixture->user,
        Laari::of($due),
        $this->account->id,
        'REF-SAME',
        Slips::jpeg(),
    ))->toThrow(DuplicateBankRefException::class);

    // Another merchant quoting it (or a slip carrying it): the verifier
    // sees the credit as spent and leaves the claim for a person.
    $topUp = claimTopUp($due, 'ref same');
    bankRow('WHOEVER', $due, 'REF-SAME');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('never spends a credit an admin booked straight into ANOTHER merchant\'s wallet', function (): void {
    $other = Merchant::factory()->create();
    app(WalletFunding::class)->recordTopUp($other, Laari::of(20000), '804802801');

    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

it('never spends a credit already used to verify a customer order', function (): void {
    $topUp = claimTopUp(20000, '804802801');

    Order::factory()->create([
        'total_payable_laari' => 20000,
        'payment_state' => 'verified',
        'matched_trx_id' => '804802801',
    ]);

    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
});

it('a matched top-up spends the credit for the other two tables as well', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $rows = app(BankHistoryClient::class)->history($this->profile, '90501400021681001');

    expect(app(BankCreditClaim::class)->taken($rows[0]))->toBeTrue();
});

it('refuses a reference the wallet already holds, and leaves the claim for a person', function (): void {
    // The admin booked this transfer straight into the wallet AFTER the
    // merchant claimed it. The (wallet, bank_ref) index refuses the second
    // credit; the claim stays pending rather than crediting twice.
    $topUp = claimTopUp(20000, '804802801');
    app(WalletFunding::class)->recordTopUp($this->merchant, Laari::of(20000), '804802801');

    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect($this->merchant->wallet()->sole()->balance_laari)->toBe(20000);
    expect(topUpJournals())->toBe(1);
});

it('does nothing at all while the flag is off', function (): void {
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    Http::assertNothingSent();
});

it('does nothing when the account paid into is not watched', function (): void {
    $this->account->forceFill(['verify_profile_id' => null])->save();

    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    Http::assertNothingSent();
});

it('leaves a claim an admin already decided alone', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    $topUp->forceFill(['state' => 'rejected'])->save();

    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp->refresh()))->toBeFalse();
    expect($this->merchant->wallet()->exists())->toBeFalse();
});

// ------------------------------------------------------------- the poll

// The claim itself pushes the first PollWalletTopUp after commit; each poll
// test re-fakes the queue AFTER claiming so it sees only the poll's own
// re-dispatch.

it('the poll matches and stops looking', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    Queue::fake();
    bankRow('WHOEVER', 20000, '804802801');

    (new PollWalletTopUp($topUp->id))->handle(app(WalletTopUpVerifier::class));

    expect($topUp->refresh()->state)->toBe('matched')
        ->and($topUp->poll_attempts)->toBe(1);
    Queue::assertNotPushed(PollWalletTopUp::class);
});

it('the poll looks again in a minute when nothing matched yet', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    Queue::fake();
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => []])]);

    (new PollWalletTopUp($topUp->id))->handle(app(WalletTopUpVerifier::class));

    expect($topUp->refresh()->poll_attempts)->toBe(1)
        ->and($topUp->state)->toBe('pending');
    Queue::assertPushed(PollWalletTopUp::class, 1);
});

it('the poll gives up when the window has closed', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);

    $topUp->forceFill(['poll_until' => now()->subMinute()])->save();

    (new PollWalletTopUp($topUp->id))->handle(app(WalletTopUpVerifier::class));

    Queue::assertNotPushed(PollWalletTopUp::class);
    Http::assertNothingSent();
});

it('the poll is bounded by attempts as well as by the clock', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    Queue::fake();
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => []])]);

    $topUp->forceFill(['poll_attempts' => 19])->save();

    (new PollWalletTopUp($topUp->id))->handle(app(WalletTopUpVerifier::class));

    expect($topUp->refresh()->poll_attempts)->toBe(20);
    Queue::assertNotPushed(PollWalletTopUp::class);
});

it('tells the store when the wallet is credited', function (): void {
    $topUp = claimTopUp(20000, '804802801');
    bankRow('WHOEVER', 20000, '804802801');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    // SMS to the store's own number (every merchant moment texts); push
    // needs a device, and the owner has none here.
    Queue::assertPushed(SendCustomerSms::class, 1);
    Queue::assertNotPushed(SendPushNotification::class);
});

/* ------------------------------------------------------------------ *
 * What the amount gate was silently doing, and what replaced it
 * (verifier round, 2026-08-25).
 * ------------------------------------------------------------------ */

it('will not take a credit off a SCRAP of somebody else\'s reference', function (): void {
    // The six-character floor is unchanged, and it was never the whole rule:
    // containment accepted any six-character SUBSTRING of a bank-issued
    // identifier, and MIB's live references are eight sequential digits. With
    // equality gone the amount no longer bounds what such a guess is worth,
    // so a merchant who has made one transfer could have taken an unclaimed
    // credit of any size by typing six of its digits.
    $topUp = claimTopUp(10000, '908633');

    // Somebody else's MVR 5,000.00.
    bankRow('MARIYAM SHIFA', 500000, '90863389');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect(topUpWalletBalance())->toBe(0);
});

it('still matches the short reference inside the bank\'s composite one', function (): void {
    // The honest transcription the containment rule exists for: a whole
    // identifier, quoted inside a longer one. Anchoring keeps this.
    $topUp = claimTopUp(20000, '1-155301335-90863389-1');

    bankRow('WHOEVER', 20000, '90863389');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();
    expect($topUp->refresh()->matched_by_rule)->toBe('reference');
});

it('will not read a reference out of a dense run of digits on a slip', function (): void {
    // The receipt_reference rung reads OCR of a slip the MERCHANT uploaded.
    // A plain containment test over its alphanumerics lets ONE crafted image
    // carrying a long digit run quote thousands of consecutive references at
    // once — an enumeration of the platform account rather than evidence
    // about one transfer.
    $topUp = claimTopUp(10000, null);
    $topUp->forceFill([
        'receipt_text' => 'RECEIPT 908633879086338890863389908633909086339190863392',
    ])->save();

    bankRow('MARIYAM SHIFA', 500000, '90863389');

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeFalse();
    expect($topUp->refresh()->state)->toBe('pending');
    expect(topUpWalletBalance())->toBe(0);
});

it('takes the row the merchant\'s own reference names, not the first row that answers', function (): void {
    // The amount was quietly acting as the ROW SELECTOR: it skipped every row
    // that was not this merchant's transfer. Without it, first-hit means the
    // BANK'S ORDERING decides which credit becomes their money — here a
    // weaker receipt hit on an earlier row would take MVR 3.00 and leave the
    // merchant's real MVR 200.00 credit unclaimed with the window closing.
    $topUp = claimTopUp(20000, '804802801');
    $topUp->forceFill(['receipt_text' => 'RECEIPT 700000001 AND 804802801'])->save();

    bankRows([
        ['reference' => '700000001', 'laari' => 300],
        ['reference' => '804802801', 'laari' => 20000],
    ]);

    expect(app(WalletTopUpVerifier::class)->attempt($topUp))->toBeTrue();

    $topUp->refresh();

    expect($topUp->matched_by_rule)->toBe('reference')
        ->and($topUp->matched_trx_id)->toBe('804802801')
        ->and($topUp->received_laari)->toBe(20000);
    expect(topUpWalletBalance())->toBe(20000);
});
