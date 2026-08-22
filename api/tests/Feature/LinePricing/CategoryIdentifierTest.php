<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * A line may name its category by SLUG or by ID.
 *
 * The slug was always an identifier rather than a display name — it is
 * `prohibited` on update, so it cannot drift — but an integrator who would
 * rather store an integer had no way to, because the categories endpoint
 * never published the id. Both now work, and the two must agree if both are
 * sent.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    $this->fruits = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id,
        'slug' => 'fruits',
        'name_en' => 'Fruits',
        'name_dv' => 'މޭވާ',
        'mode' => 'rate',
        'rate_bp' => 500,
        'active' => true,
        'sort' => 1,
    ]);

    $this->customer = Customer::factory()->create(['customer_code' => '482917']);
    $this->user = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($this->user, 'merchant');
});

function linePayload(array $lines): array
{
    return [
        'customer_code' => '482917',
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 99999),
        'eligible_amount' => 100000,
        'lines' => $lines,
    ];
}

it('prices a line named by category_id exactly as one named by slug', function (): void {
    $bySlug = $this->postJson('/api/merchant/credits', linePayload([
        ['category' => 'fruits', 'amount_laari' => 60000],
        ['category' => null, 'amount_laari' => 40000],
    ]))->assertCreated()->json('data.cashback_laari');

    $byId = $this->postJson('/api/merchant/credits', linePayload([
        ['category_id' => $this->fruits->id, 'amount_laari' => 60000],
        ['category' => null, 'amount_laari' => 40000],
    ]))->assertCreated()->json('data.cashback_laari');

    // Same money either way — the identifier is a spelling, not a rate.
    expect($byId)->toBe($bySlug);
});

it('refuses an id belonging to another merchant, as it refuses a slug', function (): void {
    $other = Merchant::factory()->create(['status' => 'active']);
    $theirs = MerchantProductCategory::query()->create([
        'merchant_id' => $other->id,
        'slug' => 'their-secret',
        'name_en' => 'Theirs',
        'name_dv' => 'ތަކެތި',
        'mode' => 'rate',
        'rate_bp' => 900,
        'active' => true,
        'sort' => 1,
    ]);

    // Indistinguishable from an id that does not exist: an identifier must
    // never become an oracle for another merchant's categories.
    $this->postJson('/api/merchant/credits', linePayload([
        ['category_id' => $theirs->id, 'amount_laari' => 100000],
    ]))->assertStatus(422)->assertJsonPath('code', 'unknown_category');
});

it('refuses an id nobody issued', function (): void {
    $this->postJson('/api/merchant/credits', linePayload([
        ['category_id' => 999999, 'amount_laari' => 100000],
    ]))->assertStatus(422)->assertJsonPath('code', 'unknown_category');
});

it('refuses a line whose two identifiers disagree', function (): void {
    $veg = MerchantProductCategory::query()->create([
        'merchant_id' => $this->merchant->id,
        'slug' => 'veggies',
        'name_en' => 'Veggies',
        'name_dv' => 'ތަރުކާރީ',
        'mode' => 'rate',
        'rate_bp' => 300,
        'active' => true,
        'sort' => 2,
    ]);

    $this->postJson('/api/merchant/credits', linePayload([
        ['category' => 'fruits', 'category_id' => $veg->id, 'amount_laari' => 100000],
    ]))->assertStatus(422)->assertJsonPath('code', 'conflicting_category');
});

it('treats the same category sent twice as a duplicate, whichever spelling', function (): void {
    $this->postJson('/api/merchant/credits', linePayload([
        ['category' => 'fruits', 'amount_laari' => 50000],
        ['category_id' => $this->fruits->id, 'amount_laari' => 50000],
    ]))->assertStatus(422)->assertJsonPath('code', 'duplicate_category_line');
});

it('publishes the id so an integrator can actually use it', function (): void {
    // The reason ids were unusable: nothing ever told a caller what they are.
    $row = $this->getJson('/api/merchant/product-categories')
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKey('id');
});
