<?php

declare(strict_types=1);

use App\Domain\Platform\BankAccountService;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PromptDiscount\PromptFixture;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The picker row for an invoice, out of the preview payload. */
function pickerRow(array $preview, string $invoiceNo): array
{
    $row = collect($preview['transactions'])->firstWhere('invoice_no', $invoiceNo);

    expect($row)->not->toBeNull();

    return $row;
}

it('hands the picker every eligible row, its age, and its own money — computed by the server', function () {
    $fixture = PromptFixture::fourLines();
    $this->actingAs($fixture->user, 'merchant');

    $preview = $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->json('data');

    expect($preview['transactions'])->toHaveCount(4);

    // The §4 first line, whole: identity, both dates the age rules turn on,
    // the stored integers and their sum. A panel never re-derives money.
    $row = pickerRow($preview, 'INV-1001');

    expect($row['id'])->toBe($fixture->transactions[0]->id)
        ->and($row['cashback_laari'])->toBe(2_000)
        ->and($row['fee_laari'])->toBe(750)
        ->and($row['fee_gst_laari'])->toBe(0)
        ->and($row['due_laari'])->toBe(2_750)
        ->and($row['due_mvr'])->toBe('27.50')
        ->and($row['age_days'])->toBe(1)
        ->and($row['overdue'])->toBeFalse()
        ->and($row['selected'])->toBeTrue()
        ->and($row['clock_start_at'])->toBe($fixture->transactions[0]->clock_start_at->toIso8601String())
        ->and($row['due_at'])->toBe($fixture->transactions[0]->due_at->toIso8601String())
        ->and($row['occurred_at'])->toBe($fixture->transactions[0]->occurred_at->toIso8601String());

    // Client-side summing of the rows must land on the server's own total.
    expect(collect($preview['transactions'])->sum('due_laari'))->toBe($preview['line_total_laari'])
        ->and($preview['line_total_laari'])->toBe(11_825);
});

it('buckets every eligible row for the filter presets, with counts, totals and ids', function () {
    $fixture = PromptFixture::fourLines();
    $clockStart = CarbonImmutable::parse(PromptFixture::CLOCK_START);

    // One line eight days on the clock, one twenty-one days on and past its
    // due date. Dues 1,375 each (50,000 @ 200bp/75bp).
    $middling = $fixture->addPayable(50_000, $clockStart->subDays(7));
    $overdue = $fixture->addPayable(50_000, $clockStart->subDays(20));

    $this->actingAs($fixture->user, 'merchant');

    $preview = $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->json('data');

    expect(pickerRow($preview, $middling->invoice_no)['age_days'])->toBe(8)
        ->and(pickerRow($preview, $overdue->invoice_no)['age_days'])->toBe(21)
        ->and(pickerRow($preview, $overdue->invoice_no)['overdue'])->toBeTrue();

    $buckets = $preview['buckets'];

    expect($buckets['all']['count'])->toBe(6)
        ->and($buckets['all']['due_laari'])->toBe(11_825 + 1_375 + 1_375)
        ->and($buckets['all']['due_mvr'])->toBe('145.75')
        // "older than 5" is day 6 and beyond — the same boundary the
        // dashboard's 0–5 / 6–10 buckets use.
        ->and($buckets['older_than_5']['count'])->toBe(2)
        ->and($buckets['older_than_5']['due_laari'])->toBe(2_750)
        ->and($buckets['older_than_5']['transaction_ids'])->toBe([$overdue->id, $middling->id])
        ->and($buckets['older_than_10']['count'])->toBe(1)
        ->and($buckets['older_than_10']['due_laari'])->toBe(1_375)
        ->and($buckets['older_than_10']['transaction_ids'])->toBe([$overdue->id])
        ->and($buckets['overdue']['count'])->toBe(1)
        ->and($buckets['overdue']['transaction_ids'])->toBe([$overdue->id])
        ->and($buckets['all']['cashback_laari'])->toBe(8_600 + 1_000 + 1_000)
        ->and($buckets['all']['fee_laari'])->toBe(3_225 + 375 + 375);

    // And the presets really are selections: sending a bucket's ids back
    // prices exactly that bucket.
    $ids = implode('&', array_map(fn (int $id) => 'transaction_ids[]='.$id, $buckets['older_than_5']['transaction_ids']));

    $this->getJson('/api/merchant/settlements/preview?'.$ids)
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.line_total_laari', 2_750);
});

it('shows the discount on the preview, and says WHY when there is none', function () {
    $fixture = PromptFixture::fourLines();
    $this->actingAs($fixture->user, 'merchant');

    // Everything, all young: granted.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.discount.eligible', true)
        ->assertJsonPath('data.discount.reason_code', 'eligible')
        ->assertJsonPath('data.discount.rate_percent', '5.00')
        ->assertJsonPath('data.discount.max_age_days', 10)
        ->assertJsonPath('data.discount.discount_laari', 162)
        ->assertJsonPath('data.discount.discount_mvr', '1.62')
        ->assertJsonPath('data.discount.gst_relief_laari', 0)
        ->assertJsonPath('data.discount_laari', 162)
        ->assertJsonPath('data.amount_due_before_discount_laari', 11_825)
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->assertJsonPath('data.amount_due_mvr', '116.63')
        // The instructions quote the discounted figure — it is what the
        // merchant must actually transfer.
        ->assertJsonPath('data.payment_instructions.amount_due_laari', 11_663);

    // A subset leaves something behind: no discount, and the reason names it.
    $ids = array_slice($fixture->transactionIds(), 0, 2);

    $this->getJson('/api/merchant/settlements/preview?transaction_ids[]='.$ids[0].'&transaction_ids[]='.$ids[1])
        ->assertOk()
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.discount.eligible', false)
        ->assertJsonPath('data.discount.reason_code', 'not_all_outstanding')
        ->assertJsonPath('data.discount.discount_laari', 0)
        ->assertJsonPath('data.discount_laari', 0)
        ->assertJsonPath('data.amount_due_laari', 2_750 + 1_375)
        // The rows still describe EVERY eligible transaction — the picker
        // keeps its full list — with the selection flagged.
        ->assertJsonPath('data.transactions.0.selected', true)
        ->assertJsonPath('data.transactions.3.selected', false);

    // An old line: the reason changes, the arithmetic does not appear.
    Carbon::setTestNow(CarbonImmutable::parse(PromptFixture::CLOCK_START)->addDays(10));

    $this->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.discount.reason_code', 'line_too_old')
        ->assertJsonPath('data.amount_due_laari', 11_825)
        ->assertJsonPath('data.transactions.0.age_days', 10);
});

it('says the discount is disabled rather than pretending there is none to give', function () {
    $fixture = PromptFixture::fourLines(rateBp: 0);

    $this->actingAs($fixture->user, 'merchant')
        ->getJson('/api/merchant/settlements/preview?settle_all=1')
        ->assertOk()
        ->assertJsonPath('data.discount.eligible', false)
        ->assertJsonPath('data.discount.reason_code', 'disabled')
        ->assertJsonPath('data.discount.rate_percent', '0.00')
        ->assertJsonPath('data.discount_laari', 0)
        ->assertJsonPath('data.amount_due_laari', 11_825);
});

it('submits the previewed discounted amount over HTTP and carries the grant onto the settlement', function () {
    app(BankAccountService::class)->create([
        'bank_name' => 'bml',
        'account_no' => '7730000123456',
        'account_name' => 'Manfaa Pvt Ltd',
        'is_primary' => true,
        'active' => true,
    ]);

    $fixture = PromptFixture::fourLines();
    $this->actingAs($fixture->user, 'merchant');

    $preview = $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertOk()->json('data');

    $created = $this->post('/api/merchant/settlements', [
        'settle_all' => '1',
        'amount' => $preview['amount_due_laari'],
        'bank_ref' => 'BML-PREVIEW-DISCOUNT',
        'slip' => Slips::jpeg(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.discount_laari', 162)
        ->assertJsonPath('data.discount_mvr', '1.62')
        ->assertJsonPath('data.discount_rate_percent', '5.00')
        ->assertJsonPath('data.discount_reason', 'eligible')
        ->assertJsonPath('data.amount_due_laari', 11_663)
        ->json('data');

    expect($created['amount_due_laari'])->toBe($preview['amount_due_laari'])
        ->and($created['payment_instructions']['amount_due_laari'])->toBe(11_663)
        ->and(Settlement::query()->sole()->discount_laari)->toBe(162);

    // Nothing left to settle, so nothing left to preview.
    $this->getJson('/api/merchant/settlements/preview?settle_all=1')->assertUnprocessable();
});
