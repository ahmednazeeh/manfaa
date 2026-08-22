<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\BranchProduct;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantMarketplaceProfile;
use App\Models\MerchantProductCategory;
use App\Models\MerchantUser;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MP2 — the catalogue (PLAN-marketplace.md §2.2, §11.1 Q3).
 *
 * The shape under test: a product is DESCRIBED once by the merchant and
 * STOCKED per branch, and edits split by kind — operational fields apply on
 * the spot, public claims wait for a reviewer.
 */
beforeEach(function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 1);

    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    // isBuyable() now asks whether the SHOP may sell, not only whether the
    // shelf has one — so the fixture has to be an approved vendor.
    MerchantMarketplaceProfile::factory()->for($this->merchant)->create();
    $this->male = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Malé']);
    $this->hulhumale = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Hulhumalé']);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($this->owner, 'merchant');
});

function makeProduct($test, string $name = 'Lotus Jasmine Rice 5kg'): int
{
    return $test->postJson('/api/merchant/marketplace/products', [
        'name' => $name,
        // The merchant's OWN cashback category — the same list that prices
        // their in-store sales.
        'category_id' => MerchantProductCategory::query()
            ->where('merchant_id', $test->merchant->id)
            ->value('id'),
        'sku' => 'RICE-'.strtoupper(substr(md5($name), 0, 6)),
    ])->assertCreated()->json('data.id');
}

function listOnShelf($test, int $productId): void
{
    $test->putJson("/api/merchant/marketplace/products/{$productId}/listing", [
        'branch_id' => $test->male->id,
        'price_laari' => 8900,
        'state' => 'active',
    ])->assertOk();
}

it('serves the curated aisles a merchant files products under', function () {
    $this->getJson('/api/merchant/marketplace/categories')
        ->assertOk()
        ->assertJsonPath('data.marketplace.0.slug', 'rice-grains')
        // Curated, not merchant-invented: otherwise "Oil", "Oils" and
        // "Cooking Oil" become three aisles by the second merchant.
        ->assertJsonCount(12, 'data.marketplace');
});

it('creates a product without queuing anything', function () {
    // A NEW product is not a change to something the public has seen. It
    // does not exist for shoppers until a branch lists it as active.
    $id = makeProduct($this);

    expect(MerchantChangeRequest::query()->count())->toBe(0)
        ->and(Product::query()->find($id)->merchant_id)->toBe($this->merchant->id);
});

it('prices and stocks the same product differently per branch', function () {
    $id = makeProduct($this);

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id,
        'price_laari' => 8900,
        'stock_qty' => 42,
        'state' => 'active',
    ])->assertOk()->assertJsonPath('data.buyable', true);

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->hulhumale->id,
        'price_laari' => 9500,
        'stock_qty' => 0,
        'state' => 'active',
    ])->assertOk()
        // Counted, and there is none — so not buyable, whatever the state.
        ->assertJsonPath('data.buyable', false);

    expect(BranchProduct::query()->count())->toBe(2)
        ->and(BranchProduct::query()->where('branch_id', $this->male->id)->value('price_laari'))->toBe(8900)
        ->and(BranchProduct::query()->where('branch_id', $this->hulhumale->id)->value('price_laari'))->toBe(9500);
});

it('treats untracked stock as availability, not absence', function () {
    // A café does not count cappuccinos. Null and zero are different
    // statements and must not share a meaning.
    $id = makeProduct($this, 'Cappuccino');

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id,
        'price_laari' => 4500,
        'stock_qty' => null,
        'state' => 'active',
    ])->assertOk()->assertJsonPath('data.buyable', true);
});

it('applies price and stock INSTANTLY on a live store', function () {
    $id = makeProduct($this);

    // The operational half. A shop that had to wait a day to mark something
    // out of stock would simply oversell.
    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id,
        'price_laari' => 8900,
        'stock_qty' => 3,
        'state' => 'active',
    ])->assertOk();

    expect(MerchantChangeRequest::query()->count())->toBe(0);

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id,
        'price_laari' => 7900,
        'stock_qty' => 0,
        'state' => 'out_of_stock',
    ])->assertOk();

    expect(MerchantChangeRequest::query()->count())->toBe(0)
        ->and(BranchProduct::query()->sole()->price_laari)->toBe(7900);
});

it('QUEUES a name change on a live store and leaves the product untouched', function () {
    $id = makeProduct($this);
    listOnShelf($this, $id);

    $this->patchJson("/api/merchant/marketplace/products/{$id}", [
        'name' => 'Lotus Jasmine Rice 10kg',
    ])->assertStatus(202)
        ->assertJsonPath('data.change_request.kind', 'product_update');

    // The public still reads the old name — that is what gated means.
    expect(Product::query()->find($id)->name)->toBe('Lotus Jasmine Rice 5kg');

    $queued = MerchantChangeRequest::query()->sole();
    expect($queued->product_id)->toBe($id)
        // The reviewer is judging words about a product, so its identity is
        // on the row even when the name is not what changed.
        ->and($queued->snapshot['product_name'])->toBe('Lotus Jasmine Rice 5kg');
});

it('applies an approved product change', function () {
    $id = makeProduct($this);
    listOnShelf($this, $id);

    $this->patchJson("/api/merchant/marketplace/products/{$id}", [
        'name' => 'Lotus Jasmine Rice 10kg',
    ])->assertStatus(202);

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $request = MerchantChangeRequest::query()->sole();

    $this->actingAs($admin, 'admin')
        ->postJson("/api/admin/change-requests/{$request->id}/approve")
        ->assertOk();

    expect(Product::query()->find($id)->name)->toBe('Lotus Jasmine Rice 10kg');
});

it('collapses two edits of one product, but never edits of two products', function () {
    $rice = makeProduct($this, 'Rice');
    $oil = makeProduct($this, 'Oil');
    listOnShelf($this, $rice);
    listOnShelf($this, $oil);

    $this->patchJson("/api/merchant/marketplace/products/{$rice}", ['name' => 'Rice A'])->assertStatus(202);
    $this->patchJson("/api/merchant/marketplace/products/{$rice}", ['name' => 'Rice B'])->assertStatus(202);
    $this->patchJson("/api/merchant/marketplace/products/{$oil}", ['name' => 'Oil A'])->assertStatus(202);

    // Two answers to one question collapse; two different products do not.
    $pending = MerchantChangeRequest::query()->where('status', 'pending')->get();

    expect($pending)->toHaveCount(2)
        ->and($pending->firstWhere('product_id', $rice)->payload['name'])->toBe('Rice B');
});

it('does not queue a re-save that moved nothing', function () {
    $id = makeProduct($this);
    listOnShelf($this, $id);

    // The panels PATCH the whole form. Re-saving an untouched product must
    // not park it in a review queue.
    $this->patchJson("/api/merchant/marketplace/products/{$id}", [
        'name' => 'Lotus Jasmine Rice 5kg',
    ])->assertOk();

    expect(MerchantChangeRequest::query()->count())->toBe(0);
});

it('edits a product nobody can buy yet without queuing anything', function () {
    // A vendor loading a 124-line catalogue must not queue 124 review
    // requests for typos in products no shelf carries. Nothing public is at
    // stake until a branch lists it.
    $id = makeProduct($this);

    $this->patchJson("/api/merchant/marketplace/products/{$id}", ['name' => 'Renamed'])
        ->assertOk();

    expect(Product::query()->find($id)->name)->toBe('Renamed')
        ->and(MerchantChangeRequest::query()->count())->toBe(0);
});

it('starts gating the moment a shelf carries it', function () {
    $id = makeProduct($this);

    $this->patchJson("/api/merchant/marketplace/products/{$id}", ['name' => 'Free edit'])
        ->assertOk();

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id, 'price_laari' => 8900, 'state' => 'active',
    ])->assertOk();

    // Now a shopper can see it, so its words are a public claim.
    $this->patchJson("/api/merchant/marketplace/products/{$id}", ['name' => 'Gated edit'])
        ->assertStatus(202);

    expect(Product::query()->find($id)->name)->toBe('Free edit');
});

it('archives rather than deletes, and pulls the product off every shelf', function () {
    $id = makeProduct($this);

    foreach ([$this->male, $this->hulhumale] as $branch) {
        $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
            'branch_id' => $branch->id, 'price_laari' => 8900, 'state' => 'active',
        ])->assertOk();
    }

    $this->deleteJson("/api/merchant/marketplace/products/{$id}")->assertOk();

    // The row survives — an order from last month names this product, and
    // history must not develop holes.
    expect(Product::query()->find($id))->not->toBeNull()
        ->and(Product::query()->find($id)->archived)->toBeTrue()
        // But a shopper must not be able to buy something it retired.
        ->and(BranchProduct::query()->where('state', 'active')->count())->toBe(0);
});

it('refuses a struck-through price that is not actually higher', function () {
    $id = makeProduct($this);

    // A "was" that is not more than the "now" is not a discount.
    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $this->male->id,
        'price_laari' => 8900,
        'compare_at_laari' => 8000,
        'state' => 'active',
    ])->assertUnprocessable();
});

it('will not list a product at another merchant\'s branch', function () {
    $id = makeProduct($this);
    $stranger = MerchantBranch::factory()->for(Merchant::factory()->create())->create();

    $this->putJson("/api/merchant/marketplace/products/{$id}/listing", [
        'branch_id' => $stranger->id, 'price_laari' => 100, 'state' => 'active',
    ])->assertUnprocessable();
});

it('will not touch another merchant\'s product', function () {
    $stranger = Merchant::factory()->create(['status' => 'active']);
    $theirs = Product::factory()->for($stranger)->create();

    $this->patchJson("/api/merchant/marketplace/products/{$theirs->id}", ['name' => 'Mine now'])
        ->assertNotFound();
});

it('accepts product artwork on the public disk', function () {
    Storage::fake('public');
    $id = makeProduct($this);

    $this->postJson("/api/merchant/marketplace/products/{$id}/images", [
        'image' => UploadedFile::fake()->image('rice.jpg'),
    ])->assertCreated()->assertJsonPath('data.sort', 10);

    // Shop photos, not identity documents — these are meant to be served.
    expect(Product::query()->find($id)->images()->count())->toBe(1);
});

it('hides the whole catalogue when the marketplace is off', function () {
    app(PlatformConfig::class)->set('marketplace_enabled', 0);

    $this->getJson('/api/merchant/marketplace/products')->assertNotFound();
    $this->getJson('/api/merchant/marketplace/categories')->assertNotFound();
});
