<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    Storage::fake('public');
});

function logoOwner(string $status = 'draft'): MerchantUser
{
    $merchant = Merchant::factory()->create(['status' => $status, 'setup_state' => []]);
    $owner = MerchantUser::factory()->for($merchant)->owner()->create();
    test()->actingAs($owner, 'merchant');

    return $owner;
}

it('stores a png logo and returns its public URL', function () {
    $owner = logoOwner();
    $merchant = $owner->merchant;

    $response = $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    $url = $response->json('data.logo_url');
    expect($url)->toContain('/storage/merchants/'.$merchant->id.'/logo.png');

    Storage::disk('public')->assertExists('merchants/'.$merchant->id.'/logo.png');

    $merchant->refresh();
    expect($merchant->logo_path)->toBe('merchants/'.$merchant->id.'/logo.png')
        ->and((array) $merchant->setup_state)->toHaveKey('logo');
});

it('rejects SVG outright — scriptable content never lands on the public disk', function () {
    $owner = logoOwner();

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->post('/api/merchant/setup/logo', ['logo' => $svg], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect(Storage::disk('public')->allFiles())->toBe([])
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

it('deletes the old file when the logo is replaced with a different extension', function () {
    $owner = logoOwner();
    $id = $owner->merchant->id;

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->post('/api/merchant/setup/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('public')->assertMissing("merchants/{$id}/logo.png");
    Storage::disk('public')->assertExists("merchants/{$id}/logo.jpg");

    expect($owner->merchant->refresh()->logo_path)->toBe("merchants/{$id}/logo.jpg");
});

it('lets an ACTIVE merchant change its logo under settings', function () {
    $owner = logoOwner('active');

    $this->post('/api/merchant/settings/logo', [
        'logo' => UploadedFile::fake()->image('logo.webp', 300, 300),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('public')->assertExists('merchants/'.$owner->merchant->id.'/logo.webp');
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
