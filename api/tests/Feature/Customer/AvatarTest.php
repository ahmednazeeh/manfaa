<?php

declare(strict_types=1);

use App\Domain\Customers\CustomerAvatar;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
    // Avatars live on the PRIVATE disk — nothing customer-uploaded ever
    // touches the public one.
    Storage::fake(CustomerAvatar::DISK);
    Storage::fake('public');
    $this->customer = Customer::factory()->create();
});

function avatarBearer(Customer $customer): array
{
    $token = app(MobileTokenService::class)
        ->issue($customer, MobileAudience::Customer, 'Device')
        ->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

// ------------------------------------------------------------ web surface

it('stores a png avatar on the private disk and returns the capability URL', function () {
    $response = $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 400, 400),
        ], ['Accept' => 'application/json'])->assertOk();

    $url = $response->json('data.avatar_url');
    expect($url)->toContain('/api/customers/'.$this->customer->id.'/avatar/');

    $this->customer->refresh();

    // customers/{id}/{uuid}.png on the private disk — never /storage.
    expect($this->customer->avatar_path)
        ->toStartWith('customers/'.$this->customer->id.'/')
        ->toEndWith('.png');

    Storage::disk(CustomerAvatar::DISK)->assertExists($this->customer->avatar_path);
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('serves the uploaded avatar at the returned URL, publicly and immutably', function () {
    $url = $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');

    // The route carries no auth middleware — the URL answers on its own,
    // which is what lets the app's credential-less image loader render it.
    $response = $this->get($url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('404s a wrong filename for a real customer — the uuid is the authorisation', function () {
    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk();

    $this->get('/api/customers/'.$this->customer->id.'/avatar/'
        .'00000000-0000-0000-0000-000000000000.png')->assertNotFound();
});

it('404s a customer with no avatar', function () {
    $this->get('/api/customers/'.$this->customer->id.'/avatar/'
        .'00000000-0000-0000-0000-000000000000.png')->assertNotFound();
});

it('deletes the old file when the avatar is replaced, and changes the URL', function () {
    $first = $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('a.png', 400, 400),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');

    $firstPath = $this->customer->refresh()->avatar_path;

    $second = $this->post('/api/customer/avatar', [
        'avatar' => UploadedFile::fake()->image('b.webp', 400, 400),
    ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');

    $secondPath = $this->customer->refresh()->avatar_path;

    Storage::disk(CustomerAvatar::DISK)->assertMissing($firstPath);
    Storage::disk(CustomerAvatar::DISK)->assertExists($secondPath);

    // Exactly one file survives — a replace must not accumulate orphans.
    expect(Storage::disk(CustomerAvatar::DISK)->allFiles())->toHaveCount(1)
        // The uuid filename means the URL changes, so no cache anywhere
        // can keep serving the replaced image.
        ->and($second)->not->toBe($first);

    // The replaced URL is dead, not stale.
    $this->get($first)->assertNotFound();
});

it('removes the avatar: file deleted, column nulled, old URL dead', function () {
    $url = $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');

    $path = $this->customer->refresh()->avatar_path;

    $this->deleteJson('/api/customer/avatar')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    expect($this->customer->refresh()->avatar_path)->toBeNull();
    Storage::disk(CustomerAvatar::DISK)->assertMissing($path);
    $this->get($url)->assertNotFound();

    // Idempotent: removing again is a calm no-op.
    $this->deleteJson('/api/customer/avatar')->assertOk();
});

// ------------------------------------------------------------- validation

it('rejects SVG outright — scriptable content is never stored at all', function () {
    $svg = UploadedFile::fake()->createWithContent(
        'me.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', ['avatar' => $svg], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);

    expect(Storage::disk(CustomerAvatar::DISK)->allFiles())->toBe([])
        ->and($this->customer->refresh()->avatar_path)->toBeNull();
});

it('rejects an oversize upload (over 4MB)', function () {
    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 400, 400)->size(5000),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
});

it('rejects an image below the dimension sanity floor', function () {
    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 10, 10),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
});

it('rejects a non-image file with an image extension', function () {
    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('me.png', 'just text pretending'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
});

// -------------------------------------------------------------------- auth

it('refuses a guest on both write endpoints, both surfaces', function () {
    $this->postJson('/api/customer/avatar')->assertUnauthorized();
    $this->deleteJson('/api/customer/avatar')->assertUnauthorized();

    // Mobile answers in its own envelope.
    $this->postJson('/api/mobile/v1/customer/avatar')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
    $this->deleteJson('/api/mobile/v1/customer/avatar')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

// ---------------------------------------------------------- mobile surface

it('uploads and removes through the mobile token routes', function () {
    $headers = avatarBearer($this->customer);

    $url = $this->post('/api/mobile/v1/customer/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 400, 400),
    ], $headers + ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');

    expect($url)->toContain('/api/customers/'.$this->customer->id.'/avatar/');
    Storage::disk(CustomerAvatar::DISK)->assertExists($this->customer->refresh()->avatar_path);

    $this->deleteJson('/api/mobile/v1/customer/avatar', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    expect($this->customer->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk(CustomerAvatar::DISK)->allFiles())->toBe([]);
});

it('shapes mobile validation refusals in the mobile envelope', function () {
    $this->post('/api/mobile/v1/customer/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 10, 10),
    ], avatarBearer($this->customer) + ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

// ------------------------------------------------------- identity payloads

it('carries avatar_url in the web /me probe — null, then set', function () {
    $this->actingAs($this->customer, 'customer')
        ->getJson('/api/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    $url = $this->post('/api/customer/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
    ], ['Accept' => 'application/json'])->json('data.avatar_url');

    $this->getJson('/api/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $url);
});

it('carries avatar_url in mobile /me and the mobile home customer block', function () {
    $headers = avatarBearer($this->customer);

    $this->getJson('/api/mobile/v1/customer/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    $this->getJson('/api/mobile/v1/customer/home', $headers)
        ->assertOk()
        ->assertJsonPath('data.customer.avatar_url', null);

    $url = $this->post('/api/mobile/v1/customer/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
    ], $headers + ['Accept' => 'application/json'])->json('data.avatar_url');

    $this->getJson('/api/mobile/v1/customer/me', $headers)
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $url);

    $this->getJson('/api/mobile/v1/customer/home', $headers)
        ->assertOk()
        ->assertJsonPath('data.customer.avatar_url', $url);
});

it('hands the avatar to a fresh device at sign-in, beside name and code', function () {
    $this->actingAs($this->customer, 'customer')
        ->post('/api/customer/avatar', [
            'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk();

    $customer = $this->customer->fresh();
    $customer->password = 'secret-123';
    $customer->save();

    $this->postJson('/api/mobile/v1/customer/auth/token', [
        'phone' => $customer->phone,
        'password' => 'secret-123',
        'device_name' => 'Test Phone',
    ])->assertCreated()
        ->assertJsonPath('data.customer.avatar_url', CustomerAvatar::url($customer->refresh()));
});
