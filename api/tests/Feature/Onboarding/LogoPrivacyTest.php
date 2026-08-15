<?php

declare(strict_types=1);

use App\Domain\Onboarding\MerchantLogo;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Task #17b privacy fix. Logos used to sit on the public disk at
 * /storage/merchants/{id}/logo.png — a guessable path keyed on an integer
 * id, which published the branding of every store that had merely STARTED
 * the setup wizard (PLAN §1: "the store is invisible publicly until
 * approved"). They now live on the private `logos` disk and are answered
 * only by MerchantLogoController, which publishes a logo exactly while its
 * store is active.
 */
beforeEach(function () {
    Storage::fake(MerchantLogo::DISK);

    $this->makeStore = function (string $status, string $slug): Merchant {
        $merchant = Merchant::factory()->create([
            'status' => $status,
            'slug' => $slug,
            'logo_path' => 'merchants/'.$slug.'/logo.png',
        ]);

        Storage::disk(MerchantLogo::DISK)->put($merchant->logo_path, 'PNG-BYTES');

        return $merchant;
    };
});

it('serves an ACTIVE store logo to anyone', function () {
    $merchant = ($this->makeStore)('active', 'cafe-alpha');

    $response = $this->get('/api/merchants/cafe-alpha/logo')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->streamedContent())->toBe('PNG-BYTES')
        ->and($merchant->logo_path)->not->toBeNull();
});

it('404s an UNAPPROVED store logo for the public — the same answer an unknown slug gets', function (string $status) {
    ($this->makeStore)($status, 'secret-store');

    $leak = $this->get('/api/merchants/secret-store/logo')->assertNotFound();
    $this->get('/api/merchants/no-such-store/logo')->assertNotFound();

    // Same status, and the body says nothing about the store — a 404 must
    // never reveal that an unapproved store exists. (Bodies are not compared
    // byte-for-byte: APP_DEBUG is on under phpunit, so both carry a stack
    // trace whose line numbers differ by construction.)
    expect($leak->getContent())->not->toContain('secret-store');
})->with(['draft', 'pending_review', 'rejected']);

it('404s a suspended or closed store logo for the public', function (string $status) {
    ($this->makeStore)($status, 'gone-store');

    $this->get('/api/merchants/gone-store/logo')->assertNotFound();
})->with(['suspended', 'closed']);

it('serves an unapproved store logo to that store’s own users', function () {
    $merchant = ($this->makeStore)('pending_review', 'my-store');
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();

    $response = $this->actingAs($owner, 'merchant')
        ->get('/api/merchants/my-store/logo')
        ->assertOk();

    // Never cacheable by a shared cache: the answer depends on the caller.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('never serves one store’s unapproved logo to a DIFFERENT merchant', function () {
    ($this->makeStore)('pending_review', 'rival-store');
    $other = Merchant::factory()->create(['status' => 'active']);
    $intruder = MerchantUser::factory()->for($other)->owner()->create();

    $this->actingAs($intruder, 'merchant')
        ->get('/api/merchants/rival-store/logo')
        ->assertNotFound();
});

it('serves an unapproved store logo to an admin — the review queue renders it', function () {
    ($this->makeStore)('pending_review', 'queued-store');
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/api/merchants/queued-store/logo')
        ->assertOk();
});

it('never serves an unapproved store logo to a signed-in customer', function () {
    ($this->makeStore)('draft', 'draft-store');
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->get('/api/merchants/draft-store/logo')
        ->assertNotFound();
});

it('404s when the row points at a file that is gone, rather than 500ing', function () {
    $merchant = ($this->makeStore)('active', 'ghost-store');
    Storage::disk(MerchantLogo::DISK)->delete($merchant->logo_path);

    $this->get('/api/merchants/ghost-store/logo')->assertNotFound();
});

it('404s a store with no logo at all', function () {
    Merchant::factory()->create(['status' => 'active', 'slug' => 'bare-store', 'logo_path' => null]);

    $this->get('/api/merchants/bare-store/logo')->assertNotFound();
});

it('moves logos off the public disk when the privacy migration runs', function () {
    Storage::fake('public');

    // A store whose logo predates the fix: the file sits on the public disk
    // under the old, guessable merchants/{id}/logo.png path.
    $merchant = Merchant::factory()->create(['status' => 'draft', 'slug' => 'legacy-store']);
    $legacyPath = 'merchants/'.$merchant->id.'/logo.png';
    $merchant->update(['logo_path' => $legacyPath]);
    Storage::disk('public')->put($legacyPath, 'LEGACY-BYTES');

    $migration = require database_path('migrations/2026_08_15_051000_move_merchant_logos_to_private_disk.php');
    $migration->up();

    Storage::disk('public')->assertMissing($legacyPath);
    Storage::disk(MerchantLogo::DISK)->assertExists($legacyPath);
    expect(Storage::disk(MerchantLogo::DISK)->get($legacyPath))->toBe('LEGACY-BYTES')
        // The path is unchanged — it was always disk-relative, so no row is
        // rewritten and the URL keeps resolving.
        ->and($merchant->refresh()->logo_path)->toBe($legacyPath);

    // Re-running is a no-op rather than a failure.
    $migration->up();
    Storage::disk(MerchantLogo::DISK)->assertExists($legacyPath);

    // And the store is still invisible: the moved file is only reachable
    // through the authorising route, which refuses a draft store publicly.
    $this->get('/api/merchants/legacy-store/logo')->assertNotFound();
});

it('content-versions the URL so a replaced logo is never served from cache', function () {
    $first = MerchantLogo::url('cafe-alpha', 'merchants/1/aaaa.png');
    $second = MerchantLogo::url('cafe-alpha', 'merchants/1/bbbb.png');

    expect($first)->not->toBe($second)
        ->and(MerchantLogo::url('cafe-alpha', null))->toBeNull();
});
