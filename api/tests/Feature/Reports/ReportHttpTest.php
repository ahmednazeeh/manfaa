<?php

declare(strict_types=1);

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportTooLargeException;
use App\Domain\Reports\Sheet;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\ReportExport;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->admin = AdminUser::factory()->create(['role' => 'admin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

const REPORT_AUGUST = '?from=2026-08-01&to=2026-08-31';

it('serves a preview with column meta, rows and the summary', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $settlement = $fixture->payAndMatch($fixture->submit(), 4_100);

    $response = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertOk();

    $response
        ->assertJsonPath('report', 'cashback')
        ->assertJsonPath('period.from', '2026-08-01')
        ->assertJsonPath('period.to', '2026-08-31')
        ->assertJsonPath('period.timezone', 'Indian/Maldives')
        ->assertJsonPath('period.days', 31)
        ->assertJsonPath('merchant_id', null)
        ->assertJsonPath('row_count', 2)
        ->assertJsonPath('capped', false)
        ->assertJsonPath('preview.sheet', 'Transactions')
        ->assertJsonPath('preview.columns.0', ['key' => 'occurred_at', 'label' => 'Date', 'type' => 'date'])
        ->assertJsonPath('preview.columns.7', ['key' => 'eligible_laari', 'label' => 'Eligible sale', 'type' => 'money'])
        ->assertJsonPath('preview.columns.8', ['key' => 'rate_bp', 'label' => 'Rate', 'type' => 'percent'])
        ->assertJsonPath('summary.transactions.count', 2)
        ->assertJsonPath('summary.transactions.collected_laari', $settlement->amount_received_laari)
        ->assertJsonPath('sheets.0.title', 'Summary');

    $rows = $response->json('preview.rows');

    expect($rows)->toHaveCount(2)
        // Positional rows matching the column list, dates as ISO-8601 in
        // business time and money as integer laari.
        ->and($rows[0][0])->toContain('+05:00')
        ->and($rows[0][7])->toBe(100_000)
        ->and($rows[0][8])->toBe(200)
        ->and($rows[0][16])->toBe('confirmed');
});

it('caps the preview at fifty rows and says so', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 51; $i++) {
        Transaction::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-CAP-'.$i,
            'occurred_at' => CarbonImmutable::parse('2026-08-10T10:00:00+05:00')->utc(),
            'received_at' => CarbonImmutable::parse('2026-08-10T10:00:00+05:00')->utc(),
        ]);
    }

    $response = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertOk()
        ->assertJsonPath('row_count', 51)
        ->assertJsonPath('capped', true);

    expect($response->json('preview.rows'))->toHaveCount(50);
});

it('serves the payouts and earnings previews too', function () {
    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/payouts'.REPORT_AUGUST)
        ->assertOk()
        ->assertJsonPath('preview.sheet', 'Transactions')
        ->assertJsonPath('sheets.0.title', 'Summary')
        ->assertJsonPath('summary.ties.transactions_cashback_laari', 0);

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/earnings'.REPORT_AUGUST)
        ->assertOk()
        ->assertJsonPath('preview.sheet', 'Postings')
        ->assertJsonPath('sheets.0.title', 'Summary')
        ->assertJsonPath('summary.net_platform_earnings_laari', 0);
});

// ------------------------------------------------------------------ access

it('answers 404 for a report that does not exist', function () {
    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/vat'.REPORT_AUGUST)
        ->assertNotFound();
});

it('stops an unauthenticated caller, and a merchant holding a good merchant session', function () {
    // Asserted BEFORE anything acts as an admin: a guard keeps its user for
    // the rest of the test, and a 403 where a 401 was expected would mean
    // the test had authenticated itself rather than the route being open.
    $this->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)->assertUnauthorized();

    $merchantUser = MerchantUser::factory()->for(Merchant::factory())->owner()->create();

    $this->actingAs($merchantUser, 'merchant')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertUnauthorized();
});

it('refuses a plain admin: superadmin only, on the preview and on the export', function () {
    // These three cross every merchant and every customer at once, so they
    // wear the same gate as admin account management.
    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertForbidden();

    $this->actingAs($this->admin, 'admin')
        ->getJson('/api/admin/reports/cashback/export'.REPORT_AUGUST)
        ->assertForbidden();

    expect(ReportExport::query()->count())->toBe(0);
});

// -------------------------------------------------------------- validation

it('validates the window', function () {
    $acting = fn () => $this->actingAs($this->superadmin, 'admin');

    $acting()->getJson('/api/admin/reports/cashback')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from', 'to']);

    $acting()->getJson('/api/admin/reports/cashback?from=2026-08-31&to=2026-08-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    $acting()->getJson('/api/admin/reports/cashback?from=01-08-2026&to=31-08-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);

    // A year and a day is the ceiling.
    $acting()->getJson('/api/admin/reports/cashback?from=2026-01-01&to=2026-12-31')->assertOk();

    $acting()->getJson('/api/admin/reports/cashback?from=2026-01-01&to=2027-01-02')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    $acting()->getJson('/api/admin/reports/cashback'.REPORT_AUGUST.'&merchant_id=999999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['merchant_id']);
});

/**
 * A report that simply claims a row count — 50,001 real rows would be a
 * minute of inserts to prove one branch.
 */
function bindHugeReport(int $rowCount): void
{
    app()->bind(ReportFactory::class, fn (): ReportFactory => new class($rowCount) extends ReportFactory
    {
        public function __construct(private readonly int $rowCount) {}

        public function make(string $key, ReportPeriod $period, ?int $merchantId = null): Report
        {
            return new class($this->rowCount) implements Report
            {
                public function __construct(private readonly int $rowCount) {}

                public function key(): string
                {
                    return 'cashback';
                }

                public function rowCount(): int
                {
                    return $this->rowCount;
                }

                public function sheets(): array
                {
                    return [];
                }

                public function primarySheetTitle(): string
                {
                    return 'Transactions';
                }

                public function primarySheet(): Sheet
                {
                    return new Sheet('Transactions', []);
                }

                public function summary(): array
                {
                    return [];
                }
            };
        }
    });
}

it('refuses a period with more rows than the screen can show', function () {
    bindHugeReport(ReportTooLargeException::MAX_ROWS + 1);

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertStatus(422)
        ->assertJsonPath('code', 'report_too_large')
        ->assertJsonPath('row_count', 50_001)
        ->assertJsonPath('limit', 50_000)
        ->assertJsonFragment(['message' => 'This period covers 50,001 rows, over the 50,000 the export can build at once — narrow the period.']);

    // Nothing was built, so nothing is audited.
    expect(ReportExport::query()->count())->toBe(0);
});

/*
 * The .xlsx has a LOWER ceiling than the screen, and it is a measured one:
 * PhpSpreadsheet holds every cell as an object and costs ~16.5 MB per
 * thousand rows of a 22-column money sheet against the pool's 256M, so the
 * writer fatals somewhere past 12,000 — a 500 with no `report_too_large` for
 * the panel to catch and a temp workbook of customer data left in /tmp.
 * The preview builds plain arrays and is untouched by that, so it keeps the
 * owner's 50,000.
 */
it('refuses an export well below the screen cap, and still previews it', function () {
    bindHugeReport(ReportTooLargeException::MAX_EXPORT_ROWS + 1);

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback/export'.REPORT_AUGUST)
        ->assertStatus(422)
        ->assertJsonPath('code', 'report_too_large')
        ->assertJsonPath('row_count', 8_001)
        ->assertJsonPath('limit', 8_001 - 1);

    // The same period on screen is fine.
    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertOk();

    expect(ReportExport::query()->count())->toBe(0);
});

// ------------------------------------------------------------------- audit

it('writes one audit row per export, and none for a preview', function () {
    $fixture = ReportFixture::payable([100_000], discountRateBp: 0);

    $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback'.REPORT_AUGUST)
        ->assertOk();

    expect(ReportExport::query()->count())->toBe(0);

    $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/cashback/export'.REPORT_AUGUST.'&merchant_id='.$fixture->merchant->id)
        ->assertOk();

    $export = ReportExport::query()->sole();

    expect($export->admin_id)->toBe($this->superadmin->id)
        ->and($export->report)->toBe('cashback')
        ->and($export->period_from->toDateString())->toBe('2026-08-01')
        ->and($export->period_to->toDateString())->toBe('2026-08-31')
        ->and($export->merchant_id)->toBe($fixture->merchant->id)
        ->and($export->row_count)->toBe(1)
        ->and($export->created_at)->not->toBeNull();

    $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/earnings/export'.REPORT_AUGUST)
        ->assertOk();

    expect(ReportExport::query()->count())->toBe(2)
        ->and(ReportExport::query()->latest('id')->first()->report)->toBe('earnings');
});
