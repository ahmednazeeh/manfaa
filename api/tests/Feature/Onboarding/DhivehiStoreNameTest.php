<?php

use App\Domain\Customers\SmsSender;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function listedMerchant(array $attributes = []): Merchant
{
    $merchant = Merchant::factory()->create(array_merge([
        'status' => 'active',
        'featured' => true,
        'category' => 'grocery',
        'approved_at' => CarbonImmutable::now('UTC')->subDay(),
    ], $attributes));

    MerchantRate::factory()->create([
        'merchant_id' => $merchant->id,
        'rate_bp' => 200,
        'effective_from' => CarbonImmutable::now('UTC')->subDays(2),
    ]);

    return $merchant;
}

it('publishes the Dhivehi name on the card, the directory and the store page', function () {
    $merchant = listedMerchant(['name' => 'Kaanu Mart', 'name_dv' => 'ކާނު މާޓް']);

    $this->getJson('/api/discover')
        ->assertOk()
        ->assertJsonPath('data.featured.0.name', 'Kaanu Mart')
        ->assertJsonPath('data.featured.0.name_dv', 'ކާނު މާޓް');

    $this->getJson('/api/discover/merchants')
        ->assertOk()
        ->assertJsonPath('data.0.name_dv', 'ކާނު މާޓް');

    $this->getJson("/api/discover/merchants/{$merchant->slug}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Kaanu Mart')
        ->assertJsonPath('data.name_dv', 'ކާނު މާޓް');
});

/**
 * The field is optional forever. A store that never supplies one must still
 * render for a Dhivehi visitor — as the Latin name, which is the client's
 * fallback — so the API answers an explicit null rather than omitting it.
 */
it('answers a null Dhivehi name rather than omitting the key', function () {
    listedMerchant(['name' => 'Plain Store', 'name_dv' => null]);

    $this->getJson('/api/discover')
        ->assertOk()
        ->assertJsonPath('data.featured.0.name_dv', null)
        ->assertJsonStructure(['data' => ['featured' => [['name', 'name_dv', 'slug']]]]);
});

it('collects the Dhivehi name at signup without letting it reach the slug', function () {
    $phone = '+9607990111';

    $sms = new class implements SmsSender
    {
        public string $code = '';

        public function send(string $phone, string $message): void
        {
            preg_match('/\b(\d{6})\b/', $message, $matches);
            $this->code = $matches[1];
        }
    };
    $this->app->instance(SmsSender::class, $sms);
    $this->withHeader('Referer', 'http://localhost');

    $this->postJson('/api/merchant/signup/request-otp', ['phone' => $phone])->assertOk();
    $token = $this->postJson('/api/merchant/signup/verify-otp', [
        'phone' => $phone,
        'code' => $sms->code,
    ])->assertOk()->json('data.signup_token');

    $this->postJson('/api/merchant/signup/register', [
        'signup_token' => $token,
        'business_name' => 'Kaanu Mart',
        'business_name_dv' => '  ކާނު މާޓް  ',
        'email' => 'owner@kaanu.mv',
        'password' => 'correct horse battery',
    ])->assertStatus(201);

    $merchant = MerchantUser::query()->where('email', 'owner@kaanu.mv')->firstOrFail()->merchant;

    expect($merchant->name)->toBe('Kaanu Mart')
        // Trimmed on the way in.
        ->and($merchant->name_dv)->toBe('ކާނު މާޓް')
        // The slug is ASCII, derived from the LATIN name alone.
        ->and($merchant->slug)->toBe('kaanu-mart');
});

it('lets the owner set the Dhivehi name later and drops the storefront cache', function () {
    $merchant = listedMerchant(['name' => 'Kaanu Mart', 'name_dv' => null]);
    $owner = MerchantUser::factory()->owner()->create(['merchant_id' => $merchant->id]);

    // Warm the read model holding the OLD (absent) name.
    $this->getJson('/api/discover')->assertOk()->assertJsonPath('data.featured.0.name_dv', null);

    $this->actingAs($owner, 'merchant')
        ->patchJson('/api/merchant/profile', ['name_dv' => 'ކާނު މާޓް'])
        ->assertOk()
        ->assertJsonPath('data.name_dv', 'ކާނު މާޓް');

    // Immediately visible — not after the 60-second TTL expires.
    $this->getJson('/api/discover')
        ->assertOk()
        ->assertJsonPath('data.featured.0.name_dv', 'ކާނު މާޓް');
});

it('never lets the profile rename the store itself', function () {
    $merchant = listedMerchant(['name' => 'Kaanu Mart']);
    $owner = MerchantUser::factory()->owner()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($owner, 'merchant')
        ->patchJson('/api/merchant/profile', ['name' => 'Something Else', 'name_dv' => 'ކާނު'])
        ->assertOk();

    expect($merchant->refresh()->name)->toBe('Kaanu Mart')
        ->and($merchant->name_dv)->toBe('ކާނު');
});
