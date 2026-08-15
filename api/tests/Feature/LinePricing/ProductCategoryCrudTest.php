<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 500,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->staff = MerchantUser::factory()->for($this->merchant)->create(); // role staff
});

it('lets the owner create excluded and rate categories with generated slugs', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Fresh Fruits',
        'name_dv' => 'މޭވާ',
        'mode' => 'excluded',
        'sort' => 10,
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'fresh-fruits')
        ->assertJsonPath('data.mode', 'excluded')
        ->assertJsonPath('data.rate_bp', null)
        ->assertJsonPath('data.active', true);

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Veggies',
        'mode' => 'rate',
        'rate_bp' => 200,
        'sort' => 20,
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'veggies')
        ->assertJsonPath('data.mode', 'rate')
        ->assertJsonPath('data.rate_bp', 200);

    // Staff can READ the list — it feeds the credit form.
    $this->actingAs($this->staff, 'merchant');
    $this->getJson('/api/merchant/product-categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'fresh-fruits')
        ->assertJsonPath('data.1.slug', 'veggies');
});

it('refuses category writes from staff with owner_required', function () {
    $this->actingAs($this->staff, 'merchant');

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200,
    ])->assertForbidden()->assertJsonPath('code', 'owner_required');

    expect(MerchantProductCategory::query()->count())->toBe(0);
});

it('refuses category writes on a pending-review store with store_not_approved', function () {
    $this->merchant->update(['status' => 'pending_review']);
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200,
    ])->assertStatus(409)->assertJsonPath('code', 'store_not_approved');
});

it('keeps the slug immutable across renames and rejects explicit slug input', function () {
    $this->actingAs($this->owner, 'merchant');

    $id = $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200,
    ])->assertCreated()->json('data.id');

    // Rename is allowed; the slug NEVER moves (it is the public line key).
    $this->patchJson("/api/merchant/product-categories/{$id}", ['name_en' => 'Vegetables & Greens'])
        ->assertOk()
        ->assertJsonPath('data.name_en', 'Vegetables & Greens')
        ->assertJsonPath('data.slug', 'veggies');

    // Sending a slug at all is a validation error, on create and update.
    $this->patchJson("/api/merchant/product-categories/{$id}", ['slug' => 'renamed'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson('/api/merchant/product-categories', [
        'name_en' => 'X', 'mode' => 'excluded', 'slug' => 'forced',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('uniquifies colliding slugs per merchant with numeric suffixes', function () {
    $this->actingAs($this->owner, 'merchant');

    $this->postJson('/api/merchant/product-categories', ['name_en' => 'Fruits', 'mode' => 'excluded'])
        ->assertCreated()->assertJsonPath('data.slug', 'fruits');
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'Fruits', 'mode' => 'rate', 'rate_bp' => 100])
        ->assertCreated()->assertJsonPath('data.slug', 'fruits-2');

    // Another merchant may reuse the same slug — uniqueness is per merchant.
    $other = Merchant::factory()->create();
    $otherOwner = MerchantUser::factory()->for($other)->owner()->create();
    $this->actingAs($otherOwner, 'merchant');
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'Fruits', 'mode' => 'excluded'])
        ->assertCreated()->assertJsonPath('data.slug', 'fruits');
});

it('validates rate_bp: structural 50..2000 plus the active schedule ceiling (rate_not_priced)', function () {
    $this->actingAs($this->owner, 'merchant');

    // Structural bounds are plain validation failures.
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'rate', 'rate_bp' => 30])
        ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'rate', 'rate_bp' => 2500])
        ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');

    // A rate without a mode=rate, or a rate on an exclusion, is malformed.
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'rate'])
        ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'excluded', 'rate_bp' => 200])
        ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');

    // 1200 bp is structurally legal but the seeded active schedule prices
    // only 50..1000 — refused app-side with the sellability code.
    $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'rate', 'rate_bp' => 1200])
        ->assertUnprocessable()->assertJsonPath('code', 'rate_not_priced');

    expect(MerchantProductCategory::query()->count())->toBe(0);

    // The same ceiling governs updates.
    $id = $this->postJson('/api/merchant/product-categories', ['name_en' => 'A', 'mode' => 'rate', 'rate_bp' => 1000])
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/merchant/product-categories/{$id}", ['rate_bp' => 1200])
        ->assertUnprocessable()->assertJsonPath('code', 'rate_not_priced');
});

it('switches modes coherently: rate requires a rate, excluded drops it', function () {
    $this->actingAs($this->owner, 'merchant');

    $id = $this->postJson('/api/merchant/product-categories', ['name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200])
        ->assertCreated()->json('data.id');

    // rate → excluded: the rate is dropped with the mode.
    $this->patchJson("/api/merchant/product-categories/{$id}", ['mode' => 'excluded'])
        ->assertOk()
        ->assertJsonPath('data.mode', 'excluded')
        ->assertJsonPath('data.rate_bp', null);

    // excluded → rate without a rate is refused.
    $this->patchJson("/api/merchant/product-categories/{$id}", ['mode' => 'rate'])
        ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');

    $this->patchJson("/api/merchant/product-categories/{$id}", ['mode' => 'rate', 'rate_bp' => 300])
        ->assertOk()
        ->assertJsonPath('data.mode', 'rate')
        ->assertJsonPath('data.rate_bp', 300);
});

it('deactivates softly and scopes updates to the owning merchant', function () {
    $this->actingAs($this->owner, 'merchant');

    $id = $this->postJson('/api/merchant/product-categories', ['name_en' => 'Veggies', 'mode' => 'rate', 'rate_bp' => 200])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/merchant/product-categories/{$id}", ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);

    // The row survives (soft deactivate — historical lines keep snapshots).
    expect(MerchantProductCategory::query()->whereKey($id)->exists())->toBeTrue();

    // Another merchant's owner cannot see or touch it.
    $other = Merchant::factory()->create();
    $otherOwner = MerchantUser::factory()->for($other)->owner()->create();
    $this->actingAs($otherOwner, 'merchant');
    $this->patchJson("/api/merchant/product-categories/{$id}", ['active' => true])->assertNotFound();
});

/**
 * Fruits (excluded) + Veggies (200bp) active, Old (100bp) deactivated.
 */
function lpSeedCrudCategories(Merchant $merchant): void
{
    MerchantProductCategory::query()->create([
        'merchant_id' => $merchant->id, 'slug' => 'fruits', 'name_en' => 'Fruits',
        'mode' => 'excluded', 'rate_bp' => null, 'active' => true, 'sort' => 1,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $merchant->id, 'slug' => 'veggies', 'name_en' => 'Veggies', 'name_dv' => 'ތަރުކާރީ',
        'mode' => 'rate', 'rate_bp' => 200, 'active' => true, 'sort' => 2,
    ]);
    MerchantProductCategory::query()->create([
        'merchant_id' => $merchant->id, 'slug' => 'old', 'name_en' => 'Old',
        'mode' => 'rate', 'rate_bp' => 100, 'active' => false, 'sort' => 3,
    ]);
}

it('lists ACTIVE categories to vendors under rates:read', function () {
    lpSeedCrudCategories($this->merchant);

    // §9.2 vendor listing: rates:read ability, active rows only, slug as
    // the exact lines[].category value.
    $token = $this->merchant->createToken('till', ['rates:read'])->plainTextToken;
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/merchants/me/product-categories')
        ->assertOk();

    expect($response->json('data'))->toBe([
        ['category' => 'fruits', 'name_en' => 'Fruits', 'name_dv' => null, 'mode' => 'excluded', 'rate_bp' => null],
        ['category' => 'veggies', 'name_en' => 'Veggies', 'name_dv' => 'ތަރުކާރީ', 'mode' => 'rate', 'rate_bp' => 200],
    ]);
});

it('renders ACTIVE category rates on the public store page without ids or slugs', function () {
    lpSeedCrudCategories($this->merchant);

    $data = $this->getJson('/api/discover/merchants/'.$this->merchant->slug)->assertOk()->json('data');

    // "Everything else" is the standing_rate_bp; names + mode + rate only.
    expect($data['standing_rate_bp'])->toBe(500)
        ->and($data['category_rates'])->toBe([
            ['name_en' => 'Fruits', 'name_dv' => null, 'mode' => 'excluded', 'rate_bp' => null],
            ['name_en' => 'Veggies', 'name_dv' => 'ތަރުކާރީ', 'mode' => 'rate', 'rate_bp' => 200],
        ]);
});

it('refuses the vendor category listing without the rates:read ability', function () {
    $token = $this->merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/merchants/me/product-categories')
        ->assertForbidden();
});
