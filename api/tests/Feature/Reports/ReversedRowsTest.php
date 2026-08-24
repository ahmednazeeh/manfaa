<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalOutcome;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\InvalidTransitionException;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportOptions;
use App\Domain\Reports\ReportPeriod;
use App\Models\AdminUser;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

/**
 * REFINEMENT 1 (owner, 2026-08-24): reversed rows are OUT by default.
 *
 * A reversed sale earned the customer nothing and is owed by nobody. Left on
 * the cashback report it inflated the state breakdown and invited a reader
 * to add it into what merchants owe. `include_reversed=true` puts the rows
 * back for whoever needs to see what a month reversed.
 *
 * The nuance the whole refinement turns on: the EARNINGS report is
 * ledger-derived and its reversal JOURNALS must always survive, because
 * there the reversal is the posting that takes the fee back out of income.
 * Dropping it would OVERSTATE what Manfaa earned — the exact opposite of the
 * error the flag exists to prevent on the cashback report.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function reversedPeriod(): ReportPeriod
{
    return ReportPeriod::of('2026-08-01', '2026-08-31');
}

/** The cashback report both ways round, from one set of rows. */
function cashbackBothWays(?int $merchantId = null): array
{
    return [
        new CashbackReport(reversedPeriod(), $merchantId, ReportOptions::of()),
        new CashbackReport(reversedPeriod(), $merchantId, ReportOptions::of(includeReversed: true)),
    ];
}

/**
 * Reverses one of a fixture's payable sales through the real §9.2 service,
 * so the reversal JOURNALS exist exactly as they do in production — which
 * is the whole point of the earnings half of this file.
 *
 * Stamped an hour before the fixture's own clock: §9.2 rejects a
 * future-dated reversal, and ReportFixture leaves "now" four days after its
 * base — comfortably inside August either way.
 */
function reverseSale(ReportFixture $fixture, int $index): ReversalOutcome
{
    return app(ReversalService::class)->reverse(
        $fixture->transactions[$index],
        Actor::system(),
        'customer_refund',
        CarbonImmutable::now('UTC')->subHour(),
    );
}

// ------------------------------------------------- the rows and the totals

it('leaves a reversed sale off the cashback report, and puts it back when asked', function () {
    $fixture = ReportFixture::payable([100_000, 50_000, 30_000]);

    $outcome = reverseSale($fixture, 1);

    // The fixture reversed in place rather than raising a credit memo —
    // otherwise this test would be proving nothing about reversed ROWS.
    expect($outcome->outcome)->toBe(ReversalOutcome::REVERSED)
        ->and($outcome->transaction->refresh()->state)->toBe(TransactionState::Reversed);

    [$without, $with] = cashbackBothWays();

    $invoices = fn (CashbackReport $report): array => collect($report->sheet(CashbackReport::TRANSACTIONS)->rows())
        ->map(fn (array $row): string => (string) $row[3])
        ->all();

    $reversedInvoice = (string) $fixture->transactions[1]->invoice_no;

    expect($invoices($without))->toHaveCount(2)
        ->and($invoices($without))->not->toContain($reversedInvoice)
        ->and($invoices($with))->toHaveCount(3)
        ->and($invoices($with))->toContain($reversedInvoice);
});

it('keeps a reversed sale out of the summary totals and the state breakdown', function () {
    $fixture = ReportFixture::payable([100_000, 50_000, 30_000]);

    reverseSale($fixture, 1);

    [$without, $with] = cashbackBothWays();

    $states = fn (array $summary): array => collect($summary['by_state'])->pluck('state')->all();

    expect($states($without->summary()))->not->toContain('reversed')
        ->and($states($with->summary()))->toContain('reversed');

    // The eligible sale total is the plainest proof the row is gone from the
    // arithmetic and not merely hidden from the sheet.
    expect($without->summary()['transactions']['eligible_laari'])->toBe(130_000)
        ->and($with->summary()['transactions']['eligible_laari'])->toBe(180_000);

    // The Summary SHEET says the same thing as the JSON summary. Read off
    // the "all states" row rather than by summing the column: the sheet
    // carries a row PER state and then a grand total, so the column
    // deliberately adds up to twice the money and is not a figure anybody
    // should be totalling.
    $allStates = function (CashbackReport $report): int {
        $sheet = $report->sheet(CashbackReport::SUMMARY);
        $metric = $sheet->indexOf('metric');
        $eligible = $sheet->indexOf('eligible_laari');

        foreach ($sheet->rows() as $row) {
            if ($row[$metric] === 'Transactions — all states') {
                return (int) $row[$eligible];
            }
        }

        throw new RuntimeException('The summary sheet has no all-states row.');
    };

    expect($allStates($without))->toBe(130_000)
        ->and($allStates($with))->toBe(180_000);
});

it('counts the same rows it builds, so the size cap is judged on the real report', function () {
    $fixture = ReportFixture::payable([100_000, 50_000, 30_000]);

    reverseSale($fixture, 1);

    [$without, $with] = cashbackBothWays();

    // rowCount() drives the too-large refusal. Counting rows the report will
    // not contain would refuse a period the admin could actually have had.
    expect($without->rowCount())->toBe(2)
        ->and($without->rowCount())->toBe($without->sheet(CashbackReport::TRANSACTIONS)->count())
        ->and($with->rowCount())->toBe(3)
        ->and($with->rowCount())->toBe($with->sheet(CashbackReport::TRANSACTIONS)->count());
});

// ------------------------------------------------------------------ the tie

it('proves the state machine cannot reverse a settled sale, in either direction', function () {
    $fixture = ReportFixture::payable([100_000]);
    $settlement = $fixture->submit();
    $fixture->payAndMatch($settlement, $fixture->dueTotal());

    $transaction = $fixture->transactions[0]->refresh();

    // Allocation confirms. This is the hinge of the whole refinement: a row
    // that collected money is confirmed, and confirmed cannot be reversed.
    expect($transaction->state)->toBe(TransactionState::Confirmed);

    // The state machine refuses outright...
    expect(fn () => app(TransitionService::class)->reverse($transaction, Actor::system()))
        ->toThrow(InvalidTransitionException::class);

    // ...and the §9.2 service routes the request to a credit memo instead,
    // leaving the transaction confirmed and its collection intact.
    $outcome = app(ReversalService::class)->reverse(
        $transaction,
        Actor::admin(AdminUser::factory()->create()->id),
        'customer_refund',
        CarbonImmutable::now('UTC')->subHour(),
        'post-settlement correction',
    );

    expect($outcome->outcome)->toBe(ReversalOutcome::ADJUSTMENT_CREATED)
        ->and($outcome->cause)->toBe(ReversalOutcome::CAUSE_ALREADY_CONFIRMED)
        ->and($transaction->refresh()->state)->toBe(TransactionState::Confirmed);
});

it('holds the Σ collected == amount_received tie in BOTH modes', function () {
    // A reversed sale beside a settled one, in the same period, for the same
    // merchant — the arrangement that would break the tie if a reversed row
    // could ever carry collected money.
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $settlement = $fixture->submit();
    $fixture->payAndMatch($settlement, $fixture->due(0));

    [$without, $with] = cashbackBothWays();

    foreach (['reversed excluded' => $without, 'reversed included' => $with] as $mode => $report) {
        $transactions = $report->sheet(CashbackReport::TRANSACTIONS);
        $settlements = $report->sheet(CashbackReport::SETTLEMENTS);

        expect($transactions->sum('collected_laari'))
            ->toBe((int) $settlement->refresh()->amount_received_laari, $mode)
            ->and($transactions->sum('collected_laari'))
            ->toBe($settlements->sum('amount_received_laari'), $mode);
    }

    // And the two modes agree on the collected figure exactly, which is the
    // statement "excluding reversals cannot cost the report a laari of
    // collection" made as one assertion.
    expect($without->sheet(CashbackReport::TRANSACTIONS)->sum('collected_laari'))
        ->toBe($with->sheet(CashbackReport::TRANSACTIONS)->sum('collected_laari'));
});

// ------------------------------------------------------- the other reports

it('leaves the earnings report identical whichever way the flag is set', function () {
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $without = new EarningsReport(reversedPeriod(), null, ReportOptions::of());
    $with = new EarningsReport(reversedPeriod(), null, ReportOptions::of(includeReversed: true));

    // The whole summary, not just one figure: nothing the flag could reach.
    expect($without->summary())->toBe($with->summary())
        ->and($without->sheet(EarningsReport::POSTINGS)->count())
        ->toBe($with->sheet(EarningsReport::POSTINGS)->count());

    // Named explicitly because it is the number the owner cares about: the
    // reversal journal is what removes the fee, so net fee income must be
    // the SAME either way — and lower than it was before the reversal.
    $reversedFee = (int) $fixture->transactions[1]->fee_laari;

    expect($without->summary()['net_fee_income_laari'])
        ->toBe($with->summary()['net_fee_income_laari'])
        ->and($reversedFee)->toBeGreaterThan(0)
        ->and($without->summary()['fee_revenue_laari'])
        ->toBe((int) $fixture->transactions[0]->fee_laari);
});

it('says so in the earnings workbook, so the two reports do not read as a contradiction', function () {
    $notes = (new EarningsReport(reversedPeriod()))->headerBlock()?->notes ?? [];

    expect(implode(' ', $notes))
        ->toContain('REVERSALS ARE ALWAYS INCLUDED HERE')
        ->toContain('TAKES the fee back out of income');

    // And the cashback workbook points back the other way.
    $cashback = implode(' ', (new CashbackReport(reversedPeriod()))->headerBlock()?->notes ?? []);

    expect($cashback)->toContain('Reversed sales are excluded')
        ->toContain('earnings report still carries their ledger');
});

it('cannot show a reversed row on the payout report at all', function () {
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $without = new PayoutReport(reversedPeriod(), null, ReportOptions::of());
    $with = new PayoutReport(reversedPeriod(), null, ReportOptions::of(includeReversed: true));

    // Nothing was paid, so both are empty — but the point is that they are
    // the SAME, and that the summary says why.
    expect($without->summary())->toBe($with->summary());

    expect(implode(' ', $without->headerBlock()?->notes ?? []))
        ->toContain('Reversed sales cannot appear on this report at all');
});

// -------------------------------------------------------------- the wire

it('defaults the parameter to false and takes it on both endpoints', function () {
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $acting = fn () => $this->actingAs($this->superadmin, 'admin');
    $window = '?from=2026-08-01&to=2026-08-31';

    // Omitted entirely.
    $acting()->getJson('/api/admin/reports/cashback'.$window)
        ->assertOk()
        ->assertJsonPath('include_reversed', false)
        ->assertJsonPath('row_count', 1);

    // Explicitly false, and the string forms a query string actually carries.
    foreach (['0', 'false'] as $falsey) {
        $acting()->getJson('/api/admin/reports/cashback'.$window.'&include_reversed='.$falsey)
            ->assertOk()
            ->assertJsonPath('include_reversed', false)
            ->assertJsonPath('row_count', 1);
    }

    foreach (['1', 'true'] as $truthy) {
        $acting()->getJson('/api/admin/reports/cashback'.$window.'&include_reversed='.$truthy)
            ->assertOk()
            ->assertJsonPath('include_reversed', true)
            ->assertJsonPath('row_count', 2);
    }

    // And it is validated rather than silently coerced.
    $acting()->getJson('/api/admin/reports/cashback'.$window.'&include_reversed=perhaps')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['include_reversed']);
});

it('records the reversed-rows choice on the audit row', function () {
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $window = '?from=2026-08-01&to=2026-08-31';

    $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/cashback/export'.$window)
        ->assertOk();

    $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/cashback/export'.$window.'&include_reversed=1')
        ->assertOk();

    $rows = ReportExport::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->include_reversed)->toBeFalse()
        ->and($rows[0]->row_count)->toBe(1)
        ->and($rows[1]->include_reversed)->toBeTrue()
        // row_count records what the admin actually got, so the two rows are
        // distinguishable on the numbers as well as on the flag.
        ->and($rows[1]->row_count)->toBe(2);
});

it('names the two files differently, so nobody sends the wrong one to an accountant', function () {
    ReportFixture::payable([100_000]);

    $window = '?from=2026-08-01&to=2026-08-31';

    $name = function (string $query): string {
        $response = $this->actingAs($this->superadmin, 'admin')->get('/api/admin/reports/cashback/export'.$query);
        $response->assertOk();

        @unlink($response->baseResponse->getFile()->getPathname());

        return $response->headers->get('content-disposition') ?? '';
    };

    expect($name($window))->toContain('manfaa-cashback-2026-08-01-2026-08-31.xlsx')
        ->and($name($window.'&include_reversed=1'))
        ->toContain('manfaa-cashback-2026-08-01-2026-08-31-with-reversed.xlsx');
});

it('does not advertise a difference on the reports the setting cannot change', function () {
    // The suffix promises two files with different totals. On payouts (paid
    // is terminal) and earnings (ledger-derived) the flag is inert, so both
    // spellings produce the SAME workbook — naming one of them
    // "-with-reversed" would be the same lie in the other direction, and the
    // audit row would record a render choice that never happened.
    $fixture = ReportFixture::payable([100_000, 50_000]);

    reverseSale($fixture, 1);

    $window = '?from=2026-08-01&to=2026-08-31';

    foreach (['payouts', 'earnings'] as $kind) {
        $response = $this->actingAs($this->superadmin, 'admin')
            ->get('/api/admin/reports/'.$kind.'/export'.$window.'&include_reversed=1');

        $response->assertOk();
        @unlink($response->baseResponse->getFile()->getPathname());

        expect($response->headers->get('content-disposition'))
            ->toContain('manfaa-'.$kind.'-2026-08-01-2026-08-31.xlsx')
            ->not->toContain('-with-reversed')
            ->and(ReportExport::query()->where('report', $kind)->sole()->include_reversed)
            ->toBeFalse();

        // The panel is told the same thing, so its badge does not announce
        // an inclusion that changed nothing.
        $this->actingAs($this->superadmin, 'admin')
            ->getJson('/api/admin/reports/'.$kind.$window.'&include_reversed=1')
            ->assertOk()
            ->assertJsonPath('include_reversed', true)
            ->assertJsonPath('reversed_rows_apply', false);
    }

    // The cashback report keeps both, because on it the flag is real.
    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.$window.'&include_reversed=1')
        ->assertOk()
        ->assertJsonPath('reversed_rows_apply', true);
});
