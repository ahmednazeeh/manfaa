<?php

declare(strict_types=1);

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\Masking;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportOptions;
use App\Domain\Reports\ReportPeriod;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * REFINEMENT 2 (owner, 2026-08-24): NOTHING IS MASKED IN AN EXPORT.
 *
 * The .xlsx is a superadmin-only, audited, tax and reconciliation artefact.
 * It is meant to be matched against a bank statement line by line, and
 * "****4821" matches against nothing — so it carries full customer names,
 * whole bank account numbers and full account names.
 *
 * The on-screen preview keeps its masking: it is a glance view rendered into
 * a JSON body, which is a far easier thing to leave lying around than a
 * downloaded file.
 *
 * Both directions are asserted from THE SAME DATA in the same run, because
 * the bug worth fearing is not "masking is wrong" but "the two renders got
 * swapped" — and a test that only ever looked at one of them would pass
 * happily through exactly that.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

const MASKING_NAME = 'Aishath Mohamed';

const MASKING_MASKED = 'Ais*** Moh***';

const MASKING_ACCOUNT = '7730000012345';

const MASKING_WINDOW = '?from=2026-08-01&to=2026-08-31';

/** One sale in August, by a customer with a name and an account worth hiding. */
function maskingFixture(): Merchant
{
    $merchant = Merchant::factory()->create(['name' => 'Sea House Cafe']);

    $customer = Customer::factory()->create([
        'name' => MASKING_NAME,
        'customer_code' => '482917',
        'payout_bank' => 'bml',
        'payout_account' => MASKING_ACCOUNT,
        'payout_account_name' => MASKING_NAME,
    ]);

    $at = CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc();

    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'invoice_no' => 'MASK-1',
        'occurred_at' => $at,
        'received_at' => $at,
    ]);

    return $merchant;
}

function maskingCustomerCell(CashbackReport $report): string
{
    $sheet = $report->sheet(CashbackReport::TRANSACTIONS);

    return (string) $sheet->rows()[0][$sheet->indexOf('customer')];
}

// ------------------------------------------------------- the two renders

it('masks the preview and leaves the export whole, from one set of rows', function () {
    maskingFixture();

    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    $preview = new CashbackReport($period, null, ReportOptions::of());
    $export = $preview->forExport();

    expect(maskingCustomerCell($preview))->toBe(MASKING_MASKED)
        ->and(maskingCustomerCell($export))->toBe(MASKING_NAME);

    // forExport() is a NEW report, not a mutation: the one we already read
    // from is still the masked one afterwards. This is the property that
    // makes the pair safe to hold at the same time.
    expect(maskingCustomerCell($preview))->toBe(MASKING_MASKED)
        ->and($preview->options->masking)->toBe(Masking::Masked)
        ->and($export->options->masking)->toBe(Masking::Full);
});

it('carries the reversed-rows choice and the merchant through forExport unchanged', function () {
    $merchant = maskingFixture();

    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    $export = (new CashbackReport($period, $merchant->id, ReportOptions::of(includeReversed: true)))->forExport();

    // Only masking changes. A forExport() that quietly reset the other
    // options would produce a workbook that disagrees with the preview
    // beside it, which is the whole thing this round is trying to prevent.
    expect($export->includeReversed())->toBeTrue()
        ->and($export->merchantId)->toBe($merchant->id)
        ->and($export->period->fromDate())->toBe('2026-08-01')
        ->and($export->period->toDate())->toBe('2026-08-31');
});

it('defaults a report nobody configured to the masked render', function () {
    maskingFixture();

    $bare = new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31'));
    $viaFactory = (new ReportFactory)->make('cashback', ReportPeriod::of('2026-08-01', '2026-08-31'));

    expect($bare->options->masking)->toBe(Masking::Masked)
        ->and($bare->includeReversed())->toBeFalse()
        ->and(maskingCustomerCell($bare))->toBe(MASKING_MASKED)
        ->and($viaFactory->previewPayload(50)['rows'][0][5])->toBe(MASKING_MASKED);
});

it('refuses outright to serialise an unmasked report into a JSON preview', function () {
    maskingFixture();

    $export = (new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31')))->forExport();

    expect(fn () => $export->previewPayload(50))
        ->toThrow(LogicException::class, 'must never be serialised into a JSON preview');
});

// --------------------------------------------------------- over the wire

it('masks every name and account the preview endpoint serves', function () {
    maskingFixture();

    $response = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.MASKING_WINDOW)
        ->assertOk();

    $body = $response->getContent();

    expect($response->json('preview.rows.0.5'))->toBe(MASKING_MASKED)
        // Nothing anywhere in the body, not merely nothing in the cell we
        // happen to look at: a summary block or a header fact that leaked
        // the name would pass a per-cell assertion.
        ->and($body)->not->toContain(MASKING_NAME)
        ->and($body)->not->toContain(MASKING_ACCOUNT);
});

it('writes full names and whole account numbers into the workbook', function () {
    maskingFixture();

    $response = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/cashback/export'.MASKING_WINDOW);

    $response->assertOk();

    $file = $response->baseResponse->getFile()->getPathname();
    $workbook = IOFactory::createReader(IOFactory::READER_XLSX)->load($file);
    @unlink($file);

    $sheet = $workbook->getSheetByName(CashbackReport::TRANSACTIONS);

    expect($sheet?->getCell('F2')->getValue())->toBe(MASKING_NAME);
});

it('writes the whole bank account and account name onto the payouts sheet', function () {
    // A wallet withdrawal is the cheapest row that carries both an account
    // number and an account name, and it exercises the second of the two
    // masking helpers as well as the first.
    $customer = Customer::factory()->create([
        'name' => MASKING_NAME,
        'customer_code' => '482918',
    ]);

    DB::table('customer_payouts')->insert([
        'customer_id' => $customer->id,
        'amount_laari' => 5_000,
        'currency' => 'MVR',
        'bank' => 'bml',
        'account' => MASKING_ACCOUNT,
        'account_name' => MASKING_NAME,
        'internal_ref' => 'WD-MASK-1',
        'state' => 'sent',
        'trx_id' => 'BML-WD-777',
        'requested_at' => CarbonImmutable::parse('2026-08-12T09:00:00+05:00')->utc(),
        'processed_at' => CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc(),
        'created_at' => CarbonImmutable::parse('2026-08-12T09:00:00+05:00')->utc(),
        'updated_at' => CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc(),
    ]);

    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    $preview = new PayoutReport($period);
    $export = $preview->forExport();

    $cell = function (PayoutReport $report, string $column): string {
        $sheet = $report->sheet(PayoutReport::WITHDRAWALS);

        return (string) $sheet->rows()[0][$sheet->indexOf($column)];
    };

    expect($cell($preview, 'account'))->toBe('****2345')
        ->and($cell($preview, 'customer'))->toBe(MASKING_MASKED)
        ->and($cell($preview, 'account_name'))->toBe(MASKING_MASKED)
        ->and($cell($export, 'account'))->toBe(MASKING_ACCOUNT)
        ->and($cell($export, 'customer'))->toBe(MASKING_NAME)
        ->and($cell($export, 'account_name'))->toBe(MASKING_NAME);
});

it('leaves an absent name and an absent account blank in both renders', function () {
    $merchant = Merchant::factory()->create();
    $at = CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc();

    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => null,
        'invoice_no' => 'NO-CUSTOMER',
        'occurred_at' => $at,
        'received_at' => $at,
    ]);

    $period = ReportPeriod::of('2026-08-01', '2026-08-31');
    $report = new CashbackReport($period);

    // Empty stays empty rather than becoming '***' — an unmasked blank is
    // still a blank, and a masked one must not invent a name.
    expect(maskingCustomerCell($report))->toBe('')
        ->and(maskingCustomerCell($report->forExport()))->toBe('');
});
