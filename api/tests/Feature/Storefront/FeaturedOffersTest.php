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
    offerFor($merchant, ['title' => 'Running', 'starts_at' => $now->subDay(), 'ends_at' => $now->addDay()]);

    $titles = collect($this->getJson('/api/discover')->json('data.offers'))->pluck('title')->all();

    expect($titles)->toBe(['Running']);
});

it('publishes an offer with no artwork as a text banner', function () {
    $merchant = offerStore();
    offerFor($merchant, ['title' => 'With artwork', 'sort' => 10]);
    offerFor($merchant, ['title' => 'Words only', 'image_path' => null, 'sort' => 20]);

    $offers = $this->getJson('/api/discover')->assertOk()->json('data.offers');

    // Artwork is not a gate — it is the choice between two banners, and the
    // storefront is told which to draw rather than left to infer it.
    expect(collect($offers)->pluck('kind', 'title')->all())
        ->toBe(['With artwork' => 'image', 'Words only' => 'text']);

    expect($offers[1]['image_url'])->toBeNull()
        // A text banner still carries the live rate: it is the kind the
        // platform lays out, so the platform stands behind the number.
        ->and($offers[1]['merchant']['cashback_rate_percent'])->toBe('10.00');
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
        // Live from the moment it is saved: with no artwork it is a text
        // banner, which the storefront lays out itself.
        ->assertJsonPath('data.live', 'live')
        ->assertJsonPath('data.kind', 'text')
        ->json('data.id');

    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$id}/image", [
            'image' => UploadedFile::fake()->image(
                'banner.png',
                OfferImage::TARGET_WIDTH,
                OfferImage::TARGET_HEIGHT,
            ),
        ])
        ->assertOk()
        ->assertJsonPath('data.live', 'live')
        ->assertJsonPath('data.kind', 'image');

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

it('takes only artwork of the one published shape', function () {
    Storage::fake(OfferImage::DISK);

    $offer = offerFor(offerStore());
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    // Right ratio, wrong shape entirely: a square is refused at the door
    // rather than centre-cropped into something the designer never saw.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$offer->id}/image", [
            'image' => UploadedFile::fake()->image('square.png', 1000, 1000),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');

    // 16:9 but too small to stay sharp on a 2x phone.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$offer->id}/image", [
            'image' => UploadedFile::fake()->image('tiny.png', 320, 180),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('image');

    // A whole-pixel rounding of 16:9 is still 16:9 to a human, so it passes.
    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$offer->id}/image", [
            'image' => UploadedFile::fake()->image('close.png', 1200, 676),
        ])
        ->assertOk()
        ->assertJsonPath('data.kind', 'image');
});

it('turns an image banner back into a text one', function () {
    Storage::fake(OfferImage::DISK);

    $offer = offerFor(offerStore(), ['image_path' => null]);
    $admin = AdminUser::factory()->create(['role' => 'superadmin']);

    $this->actingAs($admin, 'admin')
        ->post("/api/admin/store-offers/{$offer->id}/image", [
            'image' => UploadedFile::fake()->image(
                'banner.png',
                OfferImage::TARGET_WIDTH,
                OfferImage::TARGET_HEIGHT,
            ),
        ])
        ->assertOk();

    $stored = $offer->refresh()->image_path;
    expect($stored)->not->toBeNull();

    $this->actingAs($admin, 'admin')
        ->deleteJson("/api/admin/store-offers/{$offer->id}/image")
        ->assertOk()
        ->assertJsonPath('data.kind', 'text')
        ->assertJsonPath('data.image_url', null)
        // Still on the storefront: it is a text banner now, not a broken one.
        ->assertJsonPath('data.live', 'live');

    Storage::disk(OfferImage::DISK)->assertMissing($stored);
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
