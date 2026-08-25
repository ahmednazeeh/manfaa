<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Money\Laari;
use App\Domain\Settlement\PromptDiscount;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Standing\Reconciler;
use App\Models\AdminUser;
use App\Models\Settlement;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\Feature\Tax\GstFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * PROMPT DISCOUNT × GST (C5). `PromptDiscount::gstRelief` has been in the
 * codebase since the incentive shipped, correct and unexercised, because
 * GST was zero everywhere. It is now provable, and this is the proof.
 *
 * THE RULE. A discount cuts the FEE, so it cuts the tax on that fee in the
 * same proportion — the tax follows the taxable amount, and a merchant
 * charged tax on a fee they were never charged is a merchant overcharged.
 * Both legs are rounded UP, in the merchant's favour, and the ledger posts
 * them separately: DR 4100 for the revenue given up, DR 2300 for the tax no
 * longer owed, CR 1100 for the two together.
 *
 * HAND DERIVATION (one 100,000 sale, §4 200bp/75bp, discount 500bp, GST 800bp):
 *
 *   on_top     fee 750  gst  60  →  relief ceil(750·500/10000)     =  38
 *                                   gst    ceil(60·38/750)         =   4
 *                                   due    2,810 − 42              = 2,768
 *   inclusive  fee 694  gst  56  →  relief ceil(694·500/10000)     =  35
 *                                   gst    ceil(56·35/694)         =   3
 *                                   due    2,750 − 38              = 2,712
 *
 * Note what the inclusive case proves: 35 + 3 = 38 = ceil(750·500/10000).
 * The merchant gets 5% off the fee they were QUOTED either way; only the
 * split between our revenue and MIRA's money differs.
 */
beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->admin = AdminUser::factory()->create();
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

function gstDiscountJournalDebits(int $settlementId, AccountCode $account): int
{
    return (int) DB::table('ledger_entries')
        ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_journals.reference_type', 'settlement')
        ->where('ledger_journals.reference_id', $settlementId)
        ->where('ledger_journals.description', 'Prompt-payment fee discount')
        ->where('ledger_accounts.code', $account->value)
        ->sum('ledger_entries.debit_laari');
}

it('relieves the GST in proportion to the fee discount, and posts each leg to its own account', function (
    string $treatment,
    int $net,
    int $gst,
    int $feeLeg,
    int $gstLeg,
) {
    GstFixture::enable(800, $treatment);

    // The fixture credits AFTER the switch, so its lines carry the tax.
    $fixture = PromptFixture::singleLine();

    expect($fixture->transactions[0]->fee_laari)->toBe($net)
        ->and($fixture->transactions[0]->fee_gst_laari)->toBe($gst);

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant))->refresh();

    $due = 2_000 + $net + $gst - ($feeLeg + $gstLeg);

    expect($settlement->fee_total_laari)->toBe($net)
        ->and($settlement->fee_gst_total_laari)->toBe($gst)
        ->and($settlement->discount_rate_bp)->toBe(500)
        ->and($settlement->discount_laari)->toBe($feeLeg + $gstLeg)
        ->and($settlement->amount_due_laari)->toBe($due)
        // Nothing has allocated yet, so nothing is posted yet.
        ->and($settlement->discount_posted_laari)->toBe(0);

    // The two legs, re-derived from the batch's own stored integers exactly
    // as the posting does.
    expect(PromptDiscount::reliefLegs($settlement))->toBe([$feeLeg, $gstLeg]);

    $allocator = app(SettlementAllocator::class);
    $allocator->matchPayment(
        $allocator->recordBankPayment($settlement->refresh(), Laari::of($due), 'BML-GST-PD'),
        $this->admin,
    );

    $settlement = $settlement->refresh();

    expect($settlement->state)->toBe(SettlementState::Settled)
        ->and($settlement->discount_posted_laari)->toBe($feeLeg + $gstLeg)
        // Each leg against its own account: revenue given up, tax no longer
        // owed. Posting the whole relief against revenue would leave the
        // platform owing MIRA tax on a fee it never charged.
        ->and(gstDiscountJournalDebits($settlement->id, AccountCode::PlatformFeeRevenue))->toBe($feeLeg)
        ->and(gstDiscountJournalDebits($settlement->id, AccountCode::FeeTaxPayable))->toBe($gstLeg)
        // Balances land where the arithmetic says.
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe($net - $feeLeg)
        ->and($this->balances->naturalBalance(AccountCode::FeeTaxPayable))->toBe($gst - $gstLeg)
        ->and($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and(app(Reconciler::class)->run()->status)->toBe('ok');
})->with([
    'on top' => ['on_top', 750, 60, 38, 4],
    'inclusive' => ['inclusive', 694, 56, 35, 3],
]);

it('cuts the fee by the rate and lets the tax follow it', function () {
    // ON TOP — the fee is 750 and the tax rides on top of it, so 5% off the
    // fee is 38 and the tax that fee no longer carries is 4.
    expect(PromptDiscount::ceilingBp(750, 500))->toBe(38)
        ->and(PromptDiscount::gstRelief(60, 38, 750))->toBe(4)
        // INCLUSIVE — the fee is 694 of revenue plus 56 of tax. 5% off the
        // revenue is 35 and the tax follows with 3; together 38, which is
        // exactly 5% of the 750 the merchant was QUOTED. That equality is
        // the point: the incentive is worth the same either way, and only
        // the split between our revenue and MIRA's money moves.
        ->and(PromptDiscount::ceilingBp(694, 500))->toBe(35)
        ->and(PromptDiscount::gstRelief(56, 35, 694))->toBe(3)
        ->and(35 + 3)->toBe(PromptDiscount::ceilingBp(750, 500));
});

it('leaves the relief at zero while GST is switched off — today, byte for byte', function () {
    $fixture = PromptFixture::singleLine();

    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant))->refresh();

    expect($settlement->fee_gst_total_laari)->toBe(0)
        ->and($settlement->discount_laari)->toBe(38)
        ->and($settlement->amount_due_laari)->toBe(2_712)
        ->and(PromptDiscount::reliefLegs($settlement))->toBe([38, 0])
        ->and(PromptDiscount::gstRelief(0, 38, 750))->toBe(0);
});

it('never relieves more tax than the batch actually carries', function () {
    // The guard inside gstRelief: a cap can push a fee discount to the whole
    // fee, and the relief must stop at the tax that exists.
    expect(PromptDiscount::gstRelief(60, 750, 750))->toBe(60)
        ->and(PromptDiscount::gstRelief(60, 800, 750))->toBe(60)
        ->and(PromptDiscount::gstRelief(60, 0, 750))->toBe(0)
        ->and(PromptDiscount::gstRelief(0, 38, 750))->toBe(0);
});

it('splits a CAPPED relief fee-leg first, so the legs still sum to what was granted', function () {
    GstFixture::enable(800, 'on_top');

    $fixture = PromptFixture::singleLine();
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant))->refresh();

    // Force the stored relief below the fee leg the rate would produce —
    // the shape a §7 credit adjustment leaves behind when it nets the batch
    // down to almost nothing.
    $settlement->forceFill(['discount_laari' => 20])->save();

    expect(PromptDiscount::reliefLegs($settlement->refresh()))->toBe([20, 0]);

    // And with room for both legs, the fee leg is filled first — the same
    // order the ledger posts them in.
    $settlement->forceFill(['discount_laari' => 42])->save();

    expect(PromptDiscount::reliefLegs($settlement->refresh()))->toBe([38, 4]);
})->group('prompt-discount');

it('carries the relief split into the settlement resource the merchant reads', function () {
    GstFixture::enable(800, 'on_top');

    $fixture = PromptFixture::singleLine();
    $builder = app(SettlementBuilder::class);
    $settlement = $builder->submit($builder->createDraft($fixture->merchant))->refresh();

    /** @var Settlement $settlement */
    $this->actingAs($fixture->user, 'merchant')
        ->getJson("/api/merchant/settlements/{$settlement->id}")
        ->assertOk()
        // Fee and GST as SEPARATE figures — never one blended number.
        ->assertJsonPath('data.fee_total_laari', 750)
        ->assertJsonPath('data.fee_gst_total_laari', 60)
        ->assertJsonPath('data.fee_gst_total_mvr', '0.60')
        ->assertJsonPath('data.discount_laari', 42)
        ->assertJsonPath('data.amount_due_laari', 2_768);
});
