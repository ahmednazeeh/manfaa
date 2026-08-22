<?php

declare(strict_types=1);

use App\Domain\Platform\BrandAsset;
use App\Models\AdminUser;
use App\Models\PlatformBrandAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The platform's five brand marks.
 *
 * The load-bearing promise is that /api/brand/{slot} ALWAYS answers an
 * image — that is what lets three frontends point an <img> at it with no
 * fallback branch and no loading state. Most of this file is that promise
 * under the conditions that would break it.
 */

beforeEach(function () {
    Storage::fake(BrandAsset::DISK);
});

function superadmin(): AdminUser
{
    return AdminUser::factory()->create(['role' => 'superadmin']);
}

it('serves a logo for every slot before anything is ever uploaded', function () {
    foreach (BrandAsset::slots() as $slot) {
        $response = $this->get('/api/brand/'.$slot);

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('image/');
        expect(strlen((string) $response->getContent()))->toBeGreaterThan(0);
    }
});

it('needs no session — these are the logos on the login pages', function () {
    // Requiring auth to fetch the mark shown to signed-out visitors would be
    // circular, and the login page would render a broken image.
    $this->get('/api/brand/landscape_light')->assertOk();
    $this->get('/api/brand/favicon')->assertOk();
});

it('refuses a slot that does not exist', function () {
    $this->get('/api/brand/wordmark_purple')->assertNotFound();
});

it('serves the uploaded mark once a superadmin sets one', function () {
    $admin = superadmin();

    $before = $this->get('/api/brand/landscape_light')->getContent();

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/landscape_light', [
            'file' => UploadedFile::fake()->image('our-logo.png', 600, 200),
        ])
        ->assertOk()
        ->assertJsonPath('data.0.slot', 'landscape_light')
        ->assertJsonPath('data.0.is_custom', true)
        ->assertJsonPath('data.0.original_name', 'our-logo.png');

    $after = $this->get('/api/brand/landscape_light');
    $after->assertOk();
    expect($after->getContent())->not->toBe($before);
    expect($after->headers->get('Content-Type'))->toBe('image/png');

    // Every other slot still answers its default — one upload is one slot.
    expect($this->get('/api/brand/square_light')->getContent())->not->toBe($after->getContent());
});

it('replaces rather than accumulates, and deletes the file it replaced', function () {
    $admin = superadmin();

    $this->actingAs($admin, 'admin')->post('/api/admin/brand/square_dark', [
        'file' => UploadedFile::fake()->image('first.png', 512, 512),
    ])->assertOk();

    $first = PlatformBrandAsset::query()->where('slot', 'square_dark')->firstOrFail()->path;
    Storage::disk(BrandAsset::DISK)->assertExists($first);

    $this->actingAs($admin, 'admin')->post('/api/admin/brand/square_dark', [
        'file' => UploadedFile::fake()->image('second.png', 512, 512),
    ])->assertOk();

    $rows = PlatformBrandAsset::query()->where('slot', 'square_dark')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->path)->not->toBe($first);
    expect($rows->first()->original_name)->toBe('second.png');

    // The superseded file does not linger on disk.
    Storage::disk(BrandAsset::DISK)->assertMissing($first);
});

it('falls back to the default when the row survives but the file is gone', function () {
    $admin = superadmin();

    $this->actingAs($admin, 'admin')->post('/api/admin/brand/landscape_dark', [
        'file' => UploadedFile::fake()->image('logo.png', 600, 200),
    ])->assertOk();

    $row = PlatformBrandAsset::query()->where('slot', 'landscape_dark')->firstOrFail();
    Storage::disk(BrandAsset::DISK)->delete($row->path);

    // A surface gets a logo, not a broken image.
    $response = $this->get('/api/brand/landscape_dark');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/');
});

it('returns to the packaged default when the upload is removed', function () {
    $admin = superadmin();
    $original = $this->get('/api/brand/favicon')->getContent();

    $this->actingAs($admin, 'admin')->post('/api/admin/brand/favicon', [
        'file' => UploadedFile::fake()->image('fav.png', 64, 64),
    ])->assertOk();

    expect($this->get('/api/brand/favicon')->getContent())->not->toBe($original);

    $this->actingAs($admin, 'admin')
        ->delete('/api/admin/brand/favicon')
        ->assertOk()
        ->assertJsonPath('data.4.slot', 'favicon')
        ->assertJsonPath('data.4.is_custom', false);

    expect($this->get('/api/brand/favicon')->getContent())->toBe($original);
    expect(PlatformBrandAsset::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------ *
 * Who may change the platform's face.
 * ------------------------------------------------------------------ */

it('refuses uploads from an ordinary admin and from nobody at all', function () {
    $admin = AdminUser::factory()->create(['role' => 'admin']);
    $file = ['file' => UploadedFile::fake()->image('logo.png', 600, 200)];

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/landscape_light', $file)
        ->assertForbidden();

    app('auth')->forgetGuards();
    $this->post('/api/admin/brand/landscape_light', $file)->assertUnauthorized();
    $this->getJson('/api/admin/brand')->assertUnauthorized();

    expect(PlatformBrandAsset::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------ *
 * What may be uploaded.
 * ------------------------------------------------------------------ */

it('refuses an SVG, which would be script running on every surface we have', function () {
    $admin = superadmin();

    // An SVG is a document. Served from our own origin it would execute on
    // manfaa.app, merchant. and admin. alike — the widest stored-XSS
    // surface the platform has.
    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/landscape_light', ['file' => $svg])
        ->assertStatus(422);

    expect(PlatformBrandAsset::query()->count())->toBe(0);
    expect($this->get('/api/brand/landscape_light')->headers->get('Content-Type'))
        ->toBe('image/svg+xml'); // the PACKAGED default, which we wrote
});

it('refuses a file that is not an image at all', function () {
    $admin = superadmin();

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/square_light', [
            'file' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
        ])
        ->assertStatus(422);

    expect(PlatformBrandAsset::query()->count())->toBe(0);
});

it('refuses a logo too small to render crisply', function () {
    $admin = superadmin();

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/landscape_light', [
            'file' => UploadedFile::fake()->image('tiny.png', 20, 8),
        ])
        ->assertStatus(422);

    expect(PlatformBrandAsset::query()->count())->toBe(0);
});

it('accepts an ico for the favicon, which has no dimensions to check', function () {
    $admin = superadmin();

    $this->actingAs($admin, 'admin')
        ->post('/api/admin/brand/favicon', [
            'file' => UploadedFile::fake()->create('site.ico', 6, 'image/x-icon'),
        ])
        ->assertOk();

    expect($this->get('/api/brand/favicon')->headers->get('Content-Type'))->toBe('image/x-icon');
});

/* ------------------------------------------------------------------ *
 * Freshness without a cache-busting token.
 * ------------------------------------------------------------------ */

it('revalidates with an ETag, so a new logo needs no rebuild of the apps', function () {
    $admin = superadmin();

    $first = $this->get('/api/brand/landscape_light');
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();

    // Unchanged: the cheap answer.
    $this->withHeader('If-None-Match', $etag)
        ->get('/api/brand/landscape_light')
        ->assertStatus(304);

    $this->actingAs($admin, 'admin')->post('/api/admin/brand/landscape_light', [
        'file' => UploadedFile::fake()->image('new.png', 600, 200),
    ])->assertOk();

    // Changed: the SAME url, same conditional request, fresh bytes. This is
    // what lets a built frontend hardcode the url and still never be stale.
    app('auth')->forgetGuards();
    $fresh = $this->withHeader('If-None-Match', $etag)->get('/api/brand/landscape_light');
    $fresh->assertOk();
    expect($fresh->headers->get('ETag'))->not->toBe($etag);
});
