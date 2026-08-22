<?php

declare(strict_types=1);

use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Transfers\BankCreditClaim;
use App\Domain\Transfers\BankHistoryClient;
use App\Domain\Transfers\BankRow;
use App\Domain\Transfers\SettlementPaymentVerifier;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The other direction: merchants paying the platform.
 *
 * The difference that shapes this file — the merchant types their OWN
 * transfer reference into the slip. A reference we then find verbatim in our
 * history is proof of a kind a name match can never be, so it wins outright
 * and does not need the name to agree.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    // The account merchants are TOLD to pay into.
    $this->ourAccount = PlatformBankAccount::query()->create([
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

    $this->fixture = SettlementFixture::payableBatch();
    $this->merchant = $this->fixture->merchant;

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();
    $this->settlement->forceFill([
        'platform_bank_account_id' => $this->ourAccount->id,
    ])->save();
});

function settlementRow(string $name, int $laari, string $reference = '804802801', array $extra = []): void
{
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => [array_merge([
        'trxNumber2' => $reference,
        'baseAmount' => $laari / 100,
        'absAmount' => $laari / 100,
        'benefName' => $name,
        'trxDate' => '2026-08-19 10:00:00',
    ], $extra)]])]);
}

function recordPayment(int $laari, ?string $bankRef): SettlementPayment
{
    return app(SettlementAllocator::class)->recordBankPayment(
        test()->settlement->refresh(),
        Laari::of($laari),
        $bankRef,
    );
}

it('matches on the reference the merchant typed, without needing the name', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    // A company transfer often arrives under an accountant's name or a bare
    // IPS label. The reference is what makes it provable anyway.
    settlementRow('SOME UNRELATED LABEL', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();
    expect($payment->state)->toBe('matched');
    expect($payment->auto_matched)->toBeTrue();
    expect($payment->matched_by_rule)->toBe('reference');
    // No admin decided this.
    expect($payment->matched_by)->toBeNull();
});

it('forgives spacing and hyphens in a retyped reference', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    // Somebody reading it off a banking app screen.
    $payment = recordPayment($due, '804-802 801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
    expect($payment->refresh()->matched_by_rule)->toBe('reference');
});

it('finds the short reference inside the bank\'s composite one', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '1-703337593-804802801-1');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
});

it('will not treat a scrap of a reference as evidence', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    // Too short to identify anything. A containment test on this would match
    // half the ledger.
    $payment = recordPayment($due, '801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('falls back to the name on the merchant\'s bank account', function (): void {
    // Not the trading name: "Agromart" is a shop, and the transfer arrives
    // from whoever owns the account.
    $this->merchant->forceFill(['bank_account_name' => 'Ahmed Nazeeh'])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, null);

    settlementRow('AHMD NAZEEH', $due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
    expect($payment->refresh()->matched_by_rule)->toBe('name');
});

it('refuses a credit from a different payer with no reference', function (): void {
    $this->merchant->forceFill(['bank_account_name' => 'Ahmed Nazeeh'])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, null);

    settlementRow('MARIYAM SHIFA', $due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('refuses when the amount is a laari out', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due - 1, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('ignores money going the wrong way', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    // A debit of the same amount is not a merchant paying us.
    settlementRow('WHOEVER', $due, '804802801', ['baseAmount' => -($due / 100)]);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('never spends a credit already used to verify a customer order', function (): void {
    // One platform account takes both, so this collision is real rather
    // than theoretical.
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    Order::factory()->create([
        'total_payable_laari' => $due,
        'payment_state' => 'verified',
        'matched_trx_id' => '804802801',
    ]);

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('allocates through the same path an admin match uses', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    // Paid in full, so the batch settles — the allocator's own doing, not a
    // second version of it.
    expect($this->settlement->refresh()->state)->toBe(SettlementState::Settled);
    expect((int) $this->settlement->amount_received_laari)->toBe($due);
});

it('does nothing at all while the flag is off', function (): void {
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    Http::assertNothingSent();
});

it('does nothing when the account paid into is not watched', function (): void {
    $this->ourAccount->forceFill(['verify_profile_id' => null])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    Http::assertNothingSent();
});

it('leaves a payment an admin already matched alone', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');
    $payment->forceFill(['state' => 'matched'])->save();

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('opens the bank-watching window when a payment is recorded', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    expect($payment->poll_until)->not->toBeNull();
    expect($payment->poll_until->diffInMinutes($payment->poll_started_at, true))->toBe(15.0);
});

/* ------------------------------------------------------------------ *
 * What the RECEIPT says.
 *
 * Settlement 5, live on 2026-08-20: Tea Plus settled MVR 59.50, the credit
 * arrived from a company called INTERBRIDGE, and no reference was typed.
 * Matching compared the bank's payer against the store's registered name,
 * scored 0, and left a plainly correct payment waiting for a person.
 *
 * The slip the merchant had already uploaded said everything needed. Its
 * real OCR text, verbatim, is the fixture below — including the transaction
 * number, which is the strongest evidence on the page.
 * ------------------------------------------------------------------ */

/** The actual OCR of settlement 5's receipt, as tesseract read it. */
const REAL_RECEIPT = 'ARO INTERBRIDGE... CLEVIDEN TRANSACTION# 90863389 FROM '
    .'INTERBRIDGE PVT LTD TE CLEVIDEN 90101480029671000 BANK MALDIVES ISLAMIC '
    .'BANK TRANSACTION TYPE QUICK TRANSFER TRANSACTION DATE 2026-08-20 22:03:01 '
    .'PROCESSED DATE 2026-08-20 22:03:01 REMARKS N/A MALDIVES ISLAMIC BANK';

it('matches on the transaction number printed on the receipt', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    // No typed reference — exactly settlement 5.
    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => REAL_RECEIPT])->save();

    settlementRow('INTERBRIDGE', $due, '90863389');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();
    expect($payment->state)->toBe('matched');
    // The strongest rule available, taken off the slip rather than a form.
    expect($payment->matched_by_rule)->toBe('receipt_reference');
    expect($payment->matched_trx_id)->toBe('90863389');
    expect($payment->auto_matched)->toBeTrue();
});

it('falls to the payer named on the receipt when the number is not on it',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        $payment = recordPayment($due, null);
        // A slip that names the payer but carries no transaction number.
        $payment->forceFill([
            'receipt_text' => 'TRANSFER SUCCESSFUL FROM INTERBRIDGE PVT LTD TO CLEVIDEN MVR 59.50',
        ])->save();

        settlementRow('INTERBRIDGE', $due, '90863389');

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
        expect($payment->refresh()->matched_by_rule)->toBe('receipt_name');
    });

it('will not match a receipt against the wrong amount', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => REAL_RECEIPT])->save();

    // Right payer, right number, wrong money. Amount is not negotiable.
    settlementRow('INTERBRIDGE', $due + 100, '90863389');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('does not match a receipt that mentions neither the payer nor the number',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        $payment = recordPayment($due, null);
        $payment->forceFill([
            'receipt_text' => 'TRANSFER SUCCESSFUL FROM SOMEBODY ELSE LTD TO CLEVIDEN',
        ])->save();

        settlementRow('INTERBRIDGE', $due, '90863389');

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
        expect($payment->refresh()->state)->toBe('pending');
    });

it('refuses a needle too short to mean anything', function (): void {
    // A two-letter payer inside a page of receipt text would match by
    // accident, and a false match here settles a bill with somebody else's
    // money.
    $due = (int) $this->settlement->amount_due_laari;

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => REAL_RECEIPT])->save();

    settlementRow('AR', $due, 'TE');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('spends a bank credit only once, even across two receipts naming it',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        // Both recorded up front: matching the first settles the batch, and
        // a settled batch rightly refuses new payments.
        $first = recordPayment($due, null);
        $first->forceFill(['receipt_text' => REAL_RECEIPT])->save();

        $second = recordPayment($due, null);
        $second->forceFill(['receipt_text' => REAL_RECEIPT])->save();

        // ONE credit in the bank, named by both receipts.
        settlementRow('INTERBRIDGE', $due, '90863389');

        expect(app(SettlementPaymentVerifier::class)->attempt($first))->toBeTrue();
        expect($first->refresh()->matched_trx_id)->toBe('90863389');

        // The trx id is spent. The second finds nothing left to claim.
        expect(app(SettlementPaymentVerifier::class)->attempt($second))->toBeFalse();
        expect($second->refresh()->state)->toBe('pending');
    });

it('still falls back to the registered name when the slip is unreadable',
    function (): void {
        // Nothing regresses for a receipt OCR could make nothing of.
        $due = (int) $this->settlement->amount_due_laari;
        $this->merchant->forceFill(['bank_account_name' => 'Tea Plus Pvt Ltd'])->save();

        $payment = recordPayment($due, null);

        settlementRow('Tea Plus Pvt Ltd', $due, '90863392');

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
        expect($payment->refresh()->matched_by_rule)->toBe('name');
    });

it('still prefers a typed reference over anything on the slip', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    $payment = recordPayment($due, '804802801');
    $payment->forceFill(['receipt_text' => REAL_RECEIPT])->save();

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
    expect($payment->refresh()->matched_by_rule)->toBe('reference');
});

/*
 * Settlement 8: a real BML transfer that did not auto-match.
 *
 * BML files two different identifiers for one transfer. The statement row's
 * `reference`/`id` is an internal FT id, while the reference BML's own app
 * prints on the merchant's slip lands in `narrative2`. Matching the receipt
 * against the row's `reference` alone therefore could not confirm a payment
 * whose evidence was perfect: right amount, right direction, right minute.
 *
 * Both the row and the OCR text below are verbatim from production.
 */
const BML_RECEIPT = 'THANK YOU. TRANSFER TRANSACTION IS SUCCESSFUL. 59.50 MVR '
    .'STATUS SUCCESS THANK YOU. TRANSFER TRANSACTION IS MESSAGE SUCCESSFUL. '
    .'REFERENCE BLAZ861828284421 TRANSACTION DATE 21/08/2026 03:38 FROM '
    .'AHMD.NAZEEH T CLEVIDEN PVT LTD ° 7730000757923 AMOUNT MVR 59.50 BANK OF MALDIVES';

function useBmlAccount(): void
{
    $bml = TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        'from_account' => '7730000757923',
        'upstream_profile' => 'CLEVIDEN',
        'active' => true,
        'history_only' => true,
    ]);

    test()->ourAccount->forceFill([
        'bank_name' => 'bml',
        'verify_profile_id' => $bml->id,
    ])->save();
}

/**
 * @param array<string, mixed> $extra
 * @param array<string, mixed>|null $markUsed the gateway's answer to mark-used;
 *        default: one row affected, as the real gateway answers for a known ref
 */
function bmlRow(int $laari, array $extra = [], ?array $markUsed = null): void
{
    Http::fake([
        // A matched BML credit is hidden from the shared feed straight after
        // the match (MarkBankCreditUsed, sync queue in tests) — so every BML
        // fixture must answer that call too, or it is a stray request.
        '*/bml/mark-used' => Http::response($markUsed ?? [
            'success' => true,
            'bml_transactions_affected' => 1,
            'bank_notifications_affected' => 0,
        ]),
        '*/bml/history*' => Http::response(['data' => [array_merge([
        'id' => 'FT26235BDLZB\\B26',
        'description' => 'Transfer Credit',
        'reference' => 'FT26235BDLZB\\B26',
        'bookingDate' => '2026-08-23T00:00:00+05:00',
        'valueDate' => '2026-08-23T00:00:00+05:00',
        'currency' => 'MVR',
        'amount' => $laari / 100,
        'balance' => 2060.3,
        'narrative1' => '21-08-2026 03-38-58',
        'narrative2' => 'BLAZ861828284421',
        'narrative3' => 'AHMED NAZEEH',
        'narrative4' => '',
        'minus' => false,
    ], $extra)]]),
    ]);
}

it('matches the reference BML prints on the slip, not the one it files', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    // Settlement 8 exactly: nothing typed, the payer's name abbreviated on the
    // slip ("AHMD.NAZEEH" against the bank's "AHMED NAZEEH"), and the shop
    // trading as something else entirely — so the receipt reference is the
    // only evidence there is.
    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    bmlRow($due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();
    expect($payment->state)->toBe('matched');
    expect($payment->matched_by_rule)->toBe('receipt_reference');
    expect($payment->auto_matched)->toBeTrue();
});

it('matches a BML reference the merchant typed themselves', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    // The same blind spot broke the STRONGEST rule too: a merchant who
    // correctly transcribed the reference off their slip still did not match,
    // because it is not the identifier BML files the row under.
    $payment = recordPayment($due, 'BLAZ861828284421');

    bmlRow($due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();
    expect($payment->matched_by_rule)->toBe('reference');
});

it('never treats the timestamp narrative as an identifier', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    // narrative1 is a date, and every receipt carries a date. If it were
    // treated as an identifier, this receipt — which names a DIFFERENT
    // reference and a different payer — would match on the date alone.
    $payment = recordPayment($due, null);
    $payment->forceFill([
        'receipt_text' => 'TRANSFER SUCCESSFUL REFERENCE ZZZZ999999999999 '
            .'TRANSACTION DATE 21-08-2026 03-38-58 FROM SOMEBODY ELSE MVR 59.50',
    ])->save();

    bmlRow($due, ['narrative2' => '', 'narrative3' => 'SOMEBODY UNRELATED']);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();

    $payment->refresh();
    expect($payment->state)->toBe('pending');
});

it('matches the payer the slip abbreviates, when BML files no slip reference',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        useBmlAccount();

        // The settlement-8 receipt, but the bank row carries NO narrative2 —
        // the case the owner flagged: BML does not always put the slip's
        // reference there. The only evidence left is the payer's name, and the
        // slip abbreviates it ("AHMD.NAZEEH" for "AHMED NAZEEH").
        $payment = recordPayment($due, null);
        $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

        bmlRow($due, ['narrative2' => '']);

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

        $payment->refresh();
        expect($payment->state)->toBe('matched');
        expect($payment->matched_by_rule)->toBe('receipt_name_fuzzy');
        // Scored, not asserted at 100: weaker evidence than a reference, and
        // an operator can see that in the audit trail.
        expect($payment->matched_score)->toBeLessThan(100);
        expect($payment->matched_score)->toBeGreaterThanOrEqual(60);
    });

it('will not fuzzily match a different person with a similar name', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    // NAZEEH against NASEEM is the strongest FALSE pair the matcher was
    // calibrated against. It must not match, or a stranger's transfer settles
    // this bill.
    bmlRow($due, ['narrative2' => '', 'narrative3' => 'AHMED NASEEM']);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('will not assemble a payer out of words scattered across a receipt',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        useBmlAccount();

        $payment = recordPayment($due, null);
        // Both words appear, far apart and in the wrong order, as they might
        // on any busy statement. Contiguity is what makes the rule evidence.
        $payment->forceFill([
            'receipt_text' => 'NAZEEH ENTERPRISES INVOICE 44 PAID TO CLEVIDEN PVT LTD '
                .'MVR 59.50 APPROVED BY BRANCH OFFICER AHMED',
        ])->save();

        bmlRow($due, ['narrative2' => '']);

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
        expect($payment->refresh()->state)->toBe('pending');
    });

it('refuses a fuzzy match on a single-word payer', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    // "AHMD" is on the slip and would fuzzily meet "AHMED" — but one common
    // first name is not evidence that this transfer is this settlement.
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    bmlRow($due, ['narrative2' => '', 'narrative3' => 'AHMED']);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('records every identifier the credit answered to, not just the keyed one',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        useBmlAccount();

        $payment = recordPayment($due, null);
        $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

        bmlRow($due);

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

        $payment->refresh();

        // Keyed on the reference the MERCHANT holds — BML's is consistent,
        // and it is the one they quote when they ask about a payment.
        expect($payment->matched_trx_id)->toBe('BLAZ861828284421');
        // But BOTH names are recorded, so neither is left free to be spent again.
        expect($payment->matched_trx_refs)->toContain('FT26235BDLZB\\B26');
        expect($payment->matched_trx_refs)->toContain('BLAZ861828284421');
    });

it('will not let one credit settle a bill and verify an order as well',
    function (): void {
        $due = (int) $this->settlement->amount_due_laari;

        useBmlAccount();

        $payment = recordPayment($due, null);
        $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

        bmlRow($due);

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

        // The same credit, now asked for by the ORDER side. Before the shared
        // claim check this returned false — the order side only ever looked at
        // `orders`, and the unique indexes are per-table — so one MVR 59.50
        // could mark two different things paid.
        $rows = app(BankHistoryClient::class)->history(
            TransferProfile::where('segment', 'bml')->firstOrFail(),
            '7730000757923',
        );

        expect(app(BankCreditClaim::class)->taken($rows[0]))->toBeTrue();
    });

it('guards the slip reference too, not only the statement id', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    bmlRow($due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    // The SAME transfer re-presented under a fresh statement id — which is
    // what "BML references are not always consistent" looks like in practice.
    // The slip reference is unchanged, and that alone must be enough to
    // recognise the credit as already spent.
    $rows = app(BankHistoryClient::class)->history(
        TransferProfile::where('segment', 'bml')->firstOrFail(),
        '7730000757923',
    );

    $again = new BankRow(
        reference: 'FT99999DIFFERENT\\B26',
        name: $rows[0]->name,
        amountLaari: $rows[0]->amountLaari,
        incoming: true,
        at: $rows[0]->at,
        raw: ['narrative2' => 'BLAZ861828284421'],
    );

    expect(app(BankCreditClaim::class)->taken($again))->toBeTrue();
});

/*
 * Whichever form the merchant's slip happens to carry — the payer's name, the
 * FT statement id, or the BLAZ reference BML prints — the same credit must be
 * found, and must be filed under the same key.
 */
it('resolves a BML credit from the name, the FT ref or the BLAZ ref alike',
    function (string $receipt, string $expectedRule): void {
        $due = (int) $this->settlement->amount_due_laari;

        useBmlAccount();

        $payment = recordPayment($due, null);
        $payment->forceFill(['receipt_text' => $receipt])->save();

        bmlRow($due);

        expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

        $payment->refresh();
        expect($payment->matched_by_rule)->toBe($expectedRule);
        // Always the merchant-facing reference, whatever the slip showed.
        expect($payment->matched_trx_id)->toBe('BLAZ861828284421');
    })->with([
        'the BLAZ reference' => [
            'TRANSFER SUCCESSFUL REFERENCE BLAZ861828284421 MVR 59.50',
            'receipt_reference',
        ],
        'the FT statement id' => [
            'TRANSFER SUCCESSFUL REFERENCE FT26235BDLZB\\B26 MVR 59.50',
            'receipt_reference',
        ],
        'only the payer name, abbreviated' => [
            'TRANSFER SUCCESSFUL FROM AHMD.NAZEEH TO CLEVIDEN PVT LTD MVR 59.50',
            'receipt_name_fuzzy',
        ],
    ]);

// ------------------------------------------------------------ mark-used

it('hides a matched BML credit from the shared feed, keyed on the slip reference', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    bmlRow($due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    // The call the gateway needs: refNo is the BLAZ reference the slip shows
    // and matched_trx_id keys on — never the FT statement id, which the
    // gateway would silently match nothing against.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/bml/mark-used')
        && $request['refNo'] === 'BLAZ861828284421'
        && $request['used'] === 1);
});

it('does not call mark-used for a MIB match — that upstream has no such route', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => REAL_RECEIPT])->save();

    settlementRow('INTERBRIDGE', $due, '90863389');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mark-used'));
});

it('a gateway that refuses mark-used does not unwind the match', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    // The job throws so the queue retries; with a sync queue that surfaces
    // here. The money is reconciled either way — what a failure costs is the
    // cross-platform guard, and that is worth retrying, not reverting.
    Http::fake([
        '*/bml/mark-used' => Http::response(['error' => 'upstream down'], 503),
        '*/bml/history*' => Http::response(['data' => [[
            'id' => 'FT26235BDLZB\\B26', 'reference' => 'FT26235BDLZB\\B26',
            'description' => 'Transfer Credit',
            'bookingDate' => '2026-08-23T00:00:00+05:00', 'valueDate' => '2026-08-23T00:00:00+05:00',
            'currency' => 'MVR', 'amount' => $due / 100, 'balance' => 1,
            'narrative1' => '', 'narrative2' => 'BLAZ861828284421', 'narrative3' => 'AHMED NAZEEH',
            'narrative4' => '', 'minus' => false,
        ]]]),
    ]);

    try {
        app(SettlementPaymentVerifier::class)->attempt($payment);
    } catch (\RuntimeException) {
        // the sync queue re-throws the job's refusal
    }

    expect($payment->refresh()->state)->toBe('matched');
    expect($this->settlement->refresh()->state->value)->toBe('settled');
});

it('a mark-used that matched no row is logged, not treated as success', function (): void {
    $due = (int) $this->settlement->amount_due_laari;

    useBmlAccount();

    $payment = recordPayment($due, null);
    $payment->forceFill(['receipt_text' => BML_RECEIPT])->save();

    bmlRow($due, [], ['success' => true, 'bml_transactions_affected' => 0, 'bank_notifications_affected' => 0]);

    \Illuminate\Support\Facades\Log::spy();

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => $message === 'mark-used matched no bank row')
        ->once();
});
