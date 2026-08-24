<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Domain\Reports\SettlementLineAllocation;
use App\Domain\Settlement\SettlementState;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The §4 batch: eligibles 100,000 / 50,000 / 200,000 / 80,000 at 200bp + 75bp
 * price as 2,750 / 1,375 / 5,500 / 2,200 — 11,825 due, of which 3,225 is fee.
 */
function reportEligibles(): array
{
    return [100_000, 50_000, 200_000, 80_000];
}

it('ties to the laari on a batch stamped at 10%, with the discount spread per line', function () {
    $fixture = ReportFixture::payable(reportEligibles(), discountRateBp: 1000);
    $settlement = $fixture->submit();

    // 3,225 of fee at 1000bp, §4 ceiling: 323 off, 11,502 to transfer.
    expect($settlement->discount_rate_bp)->toBe(1000)
        ->and($settlement->discount_laari)->toBe(323)
        ->and($settlement->amount_due_laari)->toBe(11_502);

    $settlement = $fixture->payAndMatch($settlement, 11_502);

    expect($settlement->state)->toBe(SettlementState::Settled);

    $allocation = SettlementLineAllocation::for($settlement);

    // Per line: 750/375/1500/600 of fee, each ceilinged at 1000bp.
    expect($allocation->discountFor($fixture->transactions[0]->id))->toBe(75)
        ->and($allocation->discountFor($fixture->transactions[1]->id))->toBe(38)
        ->and($allocation->discountFor($fixture->transactions[2]->id))->toBe(150)
        ->and($allocation->discountFor($fixture->transactions[3]->id))->toBe(60)
        ->and($allocation->totalDiscount())->toBe(323);

    expect($allocation->collectedFor($fixture->transactions[0]->id))->toBe(2_750 - 75)
        ->and($allocation->collectedFor($fixture->transactions[1]->id))->toBe(1_375 - 38)
        ->and($allocation->collectedFor($fixture->transactions[2]->id))->toBe(5_500 - 150)
        ->and($allocation->collectedFor($fixture->transactions[3]->id))->toBe(2_200 - 60);

    // The invariant the column exists for.
    expect($allocation->totalCollected())->toBe($settlement->amount_received_laari)
        ->and($allocation->totalCollected())->toBe(11_502);
});

it('ties on a batch stamped at 5%, the rate the eight live settlements carry', function () {
    $fixture = ReportFixture::payable(reportEligibles(), discountRateBp: 500);
    $settlement = $fixture->submit();

    expect($settlement->discount_rate_bp)->toBe(500)
        ->and($settlement->discount_laari)->toBe(162);

    $settlement = $fixture->payAndMatch($settlement, 11_663);

    $allocation = SettlementLineAllocation::for($settlement);

    expect($allocation->discountFor($fixture->transactions[0]->id))->toBe(38)
        ->and($allocation->discountFor($fixture->transactions[1]->id))->toBe(19)
        ->and($allocation->discountFor($fixture->transactions[2]->id))->toBe(75)
        ->and($allocation->discountFor($fixture->transactions[3]->id))->toBe(30)
        ->and($allocation->totalCollected())->toBe(11_663)
        ->and($allocation->totalCollected())->toBe($settlement->amount_received_laari);
});

it('reads the rate STAMPED on the settlement, never the platform setting as it stands now', function () {
    // The whole point of discount_rate_bp: the eight settlements priced at
    // 5% must keep reporting at 5% after the live rate moved to 10%.
    $fixture = ReportFixture::payable(reportEligibles(), discountRateBp: 500);
    $settlement = $fixture->payAndMatch($fixture->submit(), 11_663);

    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 1000);

    $allocation = SettlementLineAllocation::for($settlement->refresh());

    // 5% figures, not 10% ones.
    expect($allocation->discountFor($fixture->transactions[0]->id))->toBe(38)
        ->and($allocation->totalDiscount())->toBe(162)
        ->and($allocation->totalCollected())->toBe(11_663);
});

it('lands the per-line rounding remainder on the LAST line', function () {
    // Three tiny sales whose fees are 10 laari each. Per line at 500bp the
    // §4 ceiling is 1 laari — three of them — while the batch's own ceiling
    // over 30 laari of fee is 2. The extra laari cannot be given away, so
    // the last line takes what is left rather than what it rounded to.
    $fixture = ReportFixture::payable([1_300, 1_300, 1_300], discountRateBp: 500, minEligibleLaari: 100);
    $settlement = $fixture->submit();

    expect($settlement->fee_total_laari)->toBe(30)
        ->and($settlement->discount_laari)->toBe(2);

    $settlement = $fixture->payAndMatch($settlement, $settlement->amount_due_laari);

    $allocation = SettlementLineAllocation::for($settlement);

    expect($allocation->discountFor($fixture->transactions[0]->id))->toBe(1)
        ->and($allocation->discountFor($fixture->transactions[1]->id))->toBe(1)
        ->and($allocation->discountFor($fixture->transactions[2]->id))->toBe(0)
        ->and($allocation->totalDiscount())->toBe(2)
        ->and($allocation->totalCollected())->toBe($settlement->amount_received_laari);
});

it('lands a forgiven shortfall on the last line, and still ties', function () {
    $fixture = ReportFixture::payable(reportEligibles());
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $settlement = $fixture->submit();

    expect($settlement->discount_laari)->toBe(0)
        ->and($settlement->amount_due_laari)->toBe(11_825);

    // 45 laari short — under MVR 1, so §7 forgives it and every line
    // confirms.
    $settlement = $fixture->payAndMatch($settlement, 11_780);

    expect($settlement->state)->toBe(SettlementState::Settled);

    $allocation = SettlementLineAllocation::for($settlement);

    expect($allocation->forgivenFor($fixture->transactions[3]->id))->toBe(45)
        ->and($allocation->forgivenFor($fixture->transactions[0]->id))->toBe(0)
        // The last line collected its due less the forgiven gap.
        ->and($allocation->collectedFor($fixture->transactions[3]->id))->toBe(2_200 - 45)
        ->and($allocation->collectedFor($fixture->transactions[0]->id))->toBe(2_750)
        ->and($allocation->totalCollected())->toBe(11_780)
        ->and($allocation->totalCollected())->toBe($settlement->amount_received_laari);
});

it('leaves an unallocated line blank and still ties on the lines that were paid', function () {
    $fixture = ReportFixture::payable(reportEligibles());
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    // 6,000 covers 2,750 + 1,375 and stops: the 5,500 line is unaffordable.
    $settlement = $fixture->payAndMatch($fixture->submit(), 6_000);

    expect($settlement->state)->toBe(SettlementState::PartiallySettled);

    $allocation = SettlementLineAllocation::for($settlement);

    expect($allocation->collectedFor($fixture->transactions[0]->id))->toBe(2_750)
        // The last ALLOCATED line carries the 1,875 the payment left over —
        // real money, parked in the merchant's wallet, and part of what the
        // batch received.
        ->and($allocation->collectedFor($fixture->transactions[1]->id))->toBe(1_375 + 1_875)
        ->and($allocation->collectedFor($fixture->transactions[2]->id))->toBeNull()
        ->and($allocation->collectedFor($fixture->transactions[3]->id))->toBeNull()
        ->and($allocation->totalCollected())->toBe(6_000)
        ->and($allocation->totalCollected())->toBe($settlement->amount_received_laari);
});

it('attributes nothing at all when no line has been allocated', function () {
    $fixture = ReportFixture::payable(reportEligibles());
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $settlement = $fixture->submit();

    $allocation = SettlementLineAllocation::for($settlement);

    expect($allocation->hasAllocation())->toBeFalse()
        ->and($allocation->collectedFor($fixture->transactions[0]->id))->toBeNull()
        ->and($allocation->totalCollected())->toBe(0);
});

it('discounts fee GST proportionally, at the rate the batch was stamped with', function () {
    // GST is zero everywhere today, so the only way to prove the relief leg
    // is to hand the allocation a batch that carries some. Models built in
    // memory: the maths under test reads its inputs, not the database.
    $settlement = new Settlement;
    $settlement->forceFill([
        'id' => 4242,
        'discount_laari' => 88,
        // Fully posted — this batch completed, so the relief the ledger
        // granted is the relief it was priced at.
        'discount_posted_laari' => 88,
        'discount_rate_bp' => 1000,
        'amount_received_laari' => 2_072,
    ]);

    $lines = [];

    foreach ([[1_000, 400, 40], [500, 200, 20]] as $index => [$cashback, $fee, $gst]) {
        $line = new SettlementLine;
        $line->forceFill([
            'transaction_id' => 100 + $index,
            'cashback_laari' => $cashback,
            'fee_laari' => $fee,
            'fee_gst_laari' => $gst,
            'allocated_at' => now(),
        ]);
        $lines[] = $line;
    }

    $allocation = SettlementLineAllocation::forLines($settlement, $lines, forgivenLaari: 0);

    // Line one: fee 400 at 1000bp ceils to 40; GST 40 × 40 / 400 ceils to 4.
    // Line two: 20 + 2 — but it is last, so it takes the batch's remainder.
    expect($allocation->discountFor(100))->toBe(44)
        ->and($allocation->discountFor(101))->toBe(88 - 44)
        ->and($allocation->totalDiscount())->toBe(88)
        ->and($allocation->totalCollected())->toBe(2_072)
        ->and($allocation->collectedFor(101))->toBe(720 - 44);
});
