<?php

declare(strict_types=1);

use App\Domain\Credentials\CredentialService;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The plugin's companions (owner, 2026-08-22): a token that can describe
 * itself, a sale that can be read back, and an origin that says "web shop".
 */

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);

    $this->merchant = Merchant::factory()->create(['min_eligible_laari' => 5000]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 250,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    Customer::factory()->create(['customer_code' => '482917', 'phone' => '+9607712345']);

    // A real self-issued credential, as the panel wizard mints it.
    $issued = app(CredentialService::class)->issueForMerchantUser(
        $this->merchant,
        'My shop',
        ['transactions:write', 'rates:read'],
        MerchantUser::factory()->for($this->merchant)->owner()->create(),
    );
    $this->token = $issued->plainTextToken;
});

function readBackSale(array $overrides = [], ?string $token = null)
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.($token ?? test()->token),
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/transactions', array_merge([
        'invoice_no' => 'WOO-'.Str::random(6),
        'customer_ref' => '482917',
        'eligible_amount' => 118000,
        'sale_amount' => 125000,
    ], $overrides));
}

it('describes the token: store, abilities, origin of the grant, rate summary', function () {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('merchant.id', $this->merchant->id)
        ->assertJsonPath('merchant.name', $this->merchant->name)
        ->assertJsonPath('merchant.currency', 'MVR')
        ->assertJsonPath('credential.label', 'My shop')
        ->assertJsonPath('credential.abilities', ['transactions:write', 'rates:read'])
        ->assertJsonPath('credential.connected_from', null)
        ->assertJsonPath('rate.cashback_rate_percent', '2.50')
        ->assertJsonPath('rate.min_eligible_laari', 5000)
        ->assertJsonPath('rate.has_category_overrides', false);
});

it('refuses /v1/me without a token', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('reads a sale back with lines, and hides other merchants\' sales', function () {
    $id = readBackSale()->assertCreated()->json('transaction.id');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->getJson("/api/v1/transactions/{$id}")
        ->assertOk()
        ->assertJsonPath('transaction.id', $id)
        ->assertJsonPath('transaction.state', 'awaiting_validation')
        ->assertJsonPath('transaction.cashback_laari', 2950);

    // Another merchant holding the right ability sees nothing.
    $other = Merchant::factory()->create();
    $otherToken = $other->createToken('till', ['transactions:reverse'])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->getJson("/api/v1/transactions/{$id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'transaction_not_found');

    // And a token with neither writing ability is refused outright.
    $readOnly = $this->merchant->createToken('rates', ['rates:read'])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$readOnly)
        ->getJson("/api/v1/transactions/{$id}")
        ->assertForbidden();
});

it('records origin online_link for a code-keyed web sale, api_phone for a phone-keyed one', function () {
    $web = readBackSale(['origin' => 'online_link'])->assertCreated()->json('transaction.id');
    expect(Transaction::query()->findOrFail($web)->origin)->toBe('online_link');

    $phone = readBackSale(['origin' => 'online_link', 'customer_ref' => '+9607712345'])->assertCreated()->json('transaction.id');
    expect(Transaction::query()->findOrFail($phone)->origin)->toBe('api_phone');

    $till = readBackSale()->assertCreated()->json('transaction.id');
    expect(Transaction::query()->findOrFail($till)->origin)->toBe('pos');

    readBackSale(['origin' => 'marketplace'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
