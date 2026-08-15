<?php

use App\Domain\Storefront\OfferImage;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\StoreOffer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A featured offer is editorial placement: artwork, words and a schedule.
 * It stores NO merchant facts, so the rate, logo and category on a banner
 * are whatever the store is trading on right now — the single failure a
 * promotional surface must not have.
 */
function offerStore(array $attributes = [], int $rateBp = 1000): Merchant
{
    $merchant = Merchant::factory()->create(array_merge([
        'status' => 'active',
        'name' => 'Island Mart',
        'slug' => 'island-mart',
        'category' => 'grocery',
    ], $attributes));

    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => $rateBp,
        'effective_from' => CarbonImmutable::now('UTC')->subYear(),
        'effective_to' => null,
    ]);

    return $merchant;
}

function offerFor(Merchant $merchant, array $attributes = []): StoreOffer
{
    return StoreOffer::query()->create(array_merge([
        'merchant_id' => $merchant->id,
        'title' => 'Island Mart',
        'blurb' => 'Your one-stop shop for daily essentials and more.',
        'badge' => 'Limited time',
        'image_path' => 'offers/1/banner.png',
        'sort' => 10,
        'active' => true,
    ], $attributes));
}

it('publishes a live offer with the store read fresh', function () {
    $merchant = offerStore();
    $offer = offerFor($merchant);

    $data = $this->getJson('/api/discover')->assertOk()->json('data.offers');

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($offer->id)
        ->and($data[0]['title'])->toBe('Island Mart')
        ->and($data[0]['badge'])->toBe('Limited time')
        ->and($data[0]['image_url'])->toContain("/api/store-offers/{$offer->id}/image?v=")
        // Straight off the merchant, never copied onto the offer row.
        ->and($data[0]['merchant']['cashback_rate_percent'])->toBe('10.00')
        ->and($data[0]['merchant']['slug'])->toBe('island-mart')
        ->and($data[0]['merchant']['category'])->toBe('grocery');
});

it('follows the store rate instead of freezing it', function () {
    $merchant = offerStore(rateBp: 1000);
    offerFor($merchant);

    expect($this->getJson('/api/discover')->json('data.offers.0.merchant.cashback_rate_percent'))
        ->toBe('10.00');

    // The store cuts its rate. The banner must not keep advertising 10%.
    MerchantRate::query()->where('merchant_id', $merchant->id)->delete();
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 300,
        'effective_from' => CarbonImmutable::now('UTC')->subDay(),
        'effective_to' => null,
    ]);
    Cache::flush();

    expect($this->getJson('/api/discover')->json('data.offers.0.merchant.cashback_rate_percent'))
        ->toBe('3.00');
});

it('withholds an offer whose store stops trading', function () {
    $merchant = offerStore();
    offerFor($merchant);

    expect($this->getJson('/api/discover')->json('data.offers'))->toHaveCount(1);

    $merchant->update(['status' => 'suspended']);
    Cache::flush();

    // The banner goes with the store — the same gate, not a second one to
    // remember.
    expect($this->getJson('/api/discover')->json('data.offers'))->toBe([]);
});

it('respects the schedule and the active flag', function () {
    $merchant = offerStore();
    $now = CarbonImmutable::now('UTC');

    offerFor($merchant, ['title' => 'Scheduled', 'starts_at' => $now->addDay()]);
    offerFor($merchant, ['title' => 'Ended', 'ends_at' => $now->subHour()]);
    offerFor($merchant, ['title' => 'Switched off', 'active' => false]);
    // The banner IS the image; half a banner is worse than none.
    offerFor($merchant, ['title' => 'No artwork', 'image_path' => null]);
    offerFor($merchant, ['title' => 'Running', 'starts_at' => $now->subDay(), 'ends_at' => $now->addDay()]);

    $titles = collect($this->getJson('/api/discover')->json('data.offers'))->pluck('title')->all();

    expect($titles)->toBe(['Running']);
});

it('orders offers the way the admin curated them', function () {
    $merchant = offerStore();
    offerFor($merchant, ['title' => 'Third', 'sort' => 30]);
    offerFor($merchant, ['title' => 'First', 'sort' => 10]);
    offerFor($merchant, ['title' => 'Second', 'sort' => 20]);

    expect(collect($this->getJson('/api/discover')->json('data.offers'))->pluck('title')->all())
        ->toBe(['First', 'Second', 'Third']);
});

it('leaks no internal ids through the offer payload', function () {
    $merchant = offerStore();
    offerFor($merchant);

    $raw = $this->getJson('/api/discover')->assertOk()->getContent();

    expect($raw)->not->toContain('merchant_id')
        ->and($raw)->not->toContain('image_path');

    $offer = $this->getJson('/api/discover')->json('data.offers.0');
    expect(array_keys($offer['merchant']))->toBe([
        'name', 'name_dv', 'slug', 'logo_url', 'category', 'channel', 'cashback_rate_percent',
    ]);
});

it('lets a superadmin create, schedule and illustrate an offer', function () {
    Storage::fake(OfferImage::DISK);

    $merchant = offerStore();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $id = $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/store-offers', [
            'merchant_id' => $merchant->id,
            'title' => 'Ramadan at Island Mart',
            'blurb' => 'Stock up and earn more.',
            'badge' => 'Limited time',
            'sort' => 5,
        ])
        ->assertCreated()
        // Nothing renders without artwork, and the admin is told so rather
        // than left wondering where the banner went.
        ->assertJsonPath('data.live', 'no_image')
        ->json('data.id');

    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$id}/image", [
            'image' => UploadedFile::fake()->image('banner.png', 1200, 600),
        ])
        ->assertOk()
        ->assertJsonPath('data.live', 'live');

    Cache::flush();

    expect($this->getJson('/api/discover')->json('data.offers.0.title'))
        ->toBe('Ramadan at Island Mart');

    // Public and anonymous — the carousel paints on the signed-out page.
    $this->get("/api/store-offers/{$id}/image")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses an end date before the start, and a non-raster upload', function () {
    Storage::fake(OfferImage::DISK);

    $merchant = offerStore();
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    $now = CarbonImmutable::now('UTC');

    $this->actingAs($admin, 'admin')
        ->postJson('/api/admin/store-offers', [
            'merchant_id' => $merchant->id,
            'title' => 'Impossible',
            'starts_at' => $now->addWeek()->toIso8601String(),
            'ends_at' => $now->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');

    $offer = offerFor($merchant);

    // An SVG would be a document served from our own origin.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$offer->id}/image", [
            'image' => UploadedFile::fake()->create('banner.svg', 8, 'image/svg+xml'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');
});

it('keeps offer writes to superadmins', function () {
    $merchant = offerStore();
    $ordinary = AdminUser::factory()->create(['role' => 'admin']);

    $this->actingAs($ordinary, 'admin')
        ->postJson('/api/admin/store-offers', [
            'merchant_id' => $merchant->id,
            'title' => 'Not mine to place',
        ])
        ->assertForbidden();

    // Reading the list is ordinary admin work.
    $this->actingAs($ordinary, 'admin')
        ->getJson('/api/admin/store-offers')
        ->assertOk();
});
