<?php

declare(strict_types=1);

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Http\Resources\TransactionLineResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * THE PUBLISHED CONTRACT MUST DESCRIBE WHAT THE ENDPOINT ACTUALLY RETURNS.
 *
 * `docs/openapi.yaml` is the vendor-facing spec: POS integrators generate
 * their clients from it. Every time the v1 transaction payload grew a field
 * and the spec did not, the suite stayed green while the published contract
 * quietly stopped being true — which is exactly how `fee_gst_percent` and
 * `fee_treatment` shipped undocumented (2026-08-24 GST round).
 *
 * This is the guard: the keys the RESOURCE emits and the properties the SPEC
 * declares must be the same set, in both directions. A new field fails here
 * until it is documented; a property deleted from the resource fails here
 * until it leaves the spec.
 *
 * Deliberately parsed with a regex rather than a YAML library: the API has no
 * YAML dependency, and adding one to production so a test can read a docs
 * file would be the wrong trade. The block this reads is machine-regular —
 * two-space indentation, `properties:` under a named schema — and a spec
 * shaped differently enough to defeat this would fail the check loudly rather
 * than silently pass.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create([
        'validation_window_days' => 3,
        'min_eligible_laari' => 0,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The property names declared under one schema in docs/openapi.yaml.
 *
 * @return list<string>
 */
function specProperties(string $schema): array
{
    $spec = file_get_contents(base_path('../docs/openapi.yaml'));

    expect($spec)->not->toBeFalse();

    // The schema block: from "    <Name>:" to the next schema at the same
    // indentation.
    $matched = preg_match(
        '/^    '.preg_quote($schema, '/').':\n(.*?)(?=^    \w+:\n)/ms',
        (string) $spec,
        $block,
    );

    expect($matched)->toBe(1, "schema {$schema} not found in docs/openapi.yaml");

    // Its `properties:` sub-block, to the end of the schema.
    $matched = preg_match('/^      properties:\n(.*)/ms', $block[1], $properties);

    expect($matched)->toBe(1, "schema {$schema} declares no properties");

    preg_match_all('/^        (\w+):/m', $properties[1], $keys);

    return $keys[1];
}

it('documents every field the v1 transaction resource emits, and no field it does not', function () {
    $transaction = app(ManualCreditService::class)->credit(
        $this->merchant,
        $this->user,
        '482917',
        'INV-SPEC',
        Laari::of(100_000),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    $emitted = array_keys(
        (new TransactionResource($transaction->refresh()))->toArray(Request::create('/')),
    );

    // `lines` is present only on a lined sale; the spec documents it either
    // way, so it is asserted from the schema side below.
    expect(array_diff($emitted, specProperties('Transaction')))->toBe([])
        ->and(array_diff(specProperties('Transaction'), [...$emitted, 'lines']))->toBe([]);
});

it('documents every field a transaction LINE emits', function () {
    MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id,
        'slug' => 'veggies',
        'name_en' => 'Veggies',
        'mode' => 'rate',
        'rate_bp' => 300,
        'active' => true,
        'sort' => 1,
    ]);

    // Lined credits are priced through the endpoint that accepts a line
    // set, which is also the shape a vendor sends.
    $this->actingAs($this->user, 'merchant')
        ->postJson('/api/merchant/credits', [
            'customer_code' => '482917',
            'invoice_no' => 'INV-SPEC-LINED',
            'eligible_amount' => 100_000,
            'occurred_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(),
            'lines' => [
                ['category' => 'veggies', 'amount_laari' => 40_000],
                ['category' => null, 'amount_laari' => 60_000],
            ],
        ])
        ->assertCreated();

    $line = TransactionLine::query()->orderBy('sort')->firstOrFail();

    $emitted = array_keys(
        (new TransactionLineResource($line))->toArray(Request::create('/')),
    );

    expect(array_diff($emitted, specProperties('TransactionLine')))->toBe([])
        ->and(array_diff(specProperties('TransactionLine'), $emitted))->toBe([]);
});
