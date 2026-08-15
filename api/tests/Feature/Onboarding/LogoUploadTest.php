<?php

declare(strict_types=1);

use App\Domain\Onboarding\MerchantLogo;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    // Logos live on the PRIVATE disk now — nothing merchant-uploaded ever
    // touches the public one.
    Storage::fake(MerchantLogo::DISK);
    Storage::fake('public');
});

function logoOwner(string $status = 'draft'): MerchantUser
{
    $merchant = Merchant::factory()->create(['status' => $status, 'setup_state' => []]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    test()->actingAs($owner, 'merchant');

    return $owner;
}

it('stores a png logo on the private disk and returns the controller URL', function () {
    $owner = logoOwner();
    $merchant = $owner->merchant;

    $response = $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    $url = $response->json('data.logo_url');
    expect($url)->toContain('/api/merchants/'.$merchant->slug.'/logo?v=');

    $merchant->refresh();

    // merchants/{id}/{uuid}.png on the private disk — never /storage.
    expect($merchant->logo_path)->toStartWith('merchants/'.$merchant->id.'/')
        ->and($merchant->logo_path)->toEndWith('.png')
        ->and((array) $merchant->setup_state)->toHaveKey('logo');

    Storage::disk(MerchantLogo::DISK)->assertExists($merchant->logo_path);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects SVG outright — scriptable content is never stored at all', function () {
    $owner = logoOwner();

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->post('/api/merchant/setup/logo', ['logo' => $svg], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect(Storage::disk(MerchantLogo::DISK)->allFiles())->toBe([])
        ->and($owner->merchant->refresh()->logo_path)->toBeNull();
});

it('rejects an oversize upload (over 2MB)', function () {
    logoOwner();

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400)->size(3000),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

it('rejects an image below the dimension sanity floor', function () {
    logoOwner();

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 10, 10),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

it('rejects a non-image file with an image extension', function () {
    logoOwner();

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->createWithContent('logo.png', 'just text pretending'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

it('deletes the old file when the logo is replaced, and changes the URL', function () {
    $owner = logoOwner();

    $first = $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk()->json('data.logo_url');

    $firstPath = $owner->merchant->refresh()->logo_path;

    $second = $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk()->json('data.logo_url');

    $secondPath = $owner->merchant->refresh()->logo_path;

    Storage::disk(MerchantLogo::DISK)->assertMissing($firstPath);
    Storage::disk(MerchantLogo::DISK)->assertExists($secondPath);

    // Exactly one file survives — a replace must not accumulate orphans.
    expect(Storage::disk(MerchantLogo::DISK)->allFiles())->toHaveCount(1);

    // The uuid filename means the URL changes, so no cache anywhere can
    // keep serving the replaced image.
    expect($second)->not->toBe($first);
});

it('lets an ACTIVE merchant change its logo under settings', function () {
    $owner = logoOwner('active');

    $this->post('/api/merchant/settings/logo', [
        'logo' => UploadedFile::fake()->image('logo.webp', 300, 300),
    ], ['Accept' => 'application/json'])->assertOk();

    $path = $owner->merchant->refresh()->logo_path;

    expect($path)->toEndWith('.webp');
    Storage::disk(MerchantLogo::DISK)->assertExists($path);
});

it('refuses logo changes while the store is pending review', function () {
    logoOwner('pending_review');

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'setup_not_editable');
});

it('keeps the logo endpoints owner-only', function () {
    $owner = logoOwner();
    $staff = MerchantUser::factory()->for($owner->merchant)->create(['role' => 'staff']);
    $this->actingAs($staff, 'merchant');

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertForbidden();
});
