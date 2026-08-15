<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Referer', 'http://localhost');
});

/** A pending_review merchant with a live rate, owner password 'password'. */
function pendingMerchantOwner(): MerchantUser
{
    $merchant = Merchant::factory()->pendingReview()->create([
        'category' => 'grocery',
        'eligibility_basis' => 'Everything.',
    ]);
    MerchantRate::factory()->for($merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    return MerchantUser::factory()->for($merchant)->owner()->create();
}

it('lets a pending merchant owner LOG IN — the wizard needs the panel', function () {
    $owner = pendingMerchantOwner();

    $this->postJson('/api/merchant/auth/login', [
        'email' => $owner->email,
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.merchant.status', 'pending_review');

    $this->getJson('/api/merchant/setup')->assertOk()
        ->assertJsonPath('data.status', 'pending_review');
});

it('refuses manual credits for draft, pending and rejected merchants', function (string $status) {
    $owner = pendingMerchantOwner();
    $owner->merchant->update(['status' => $status]);
    Customer::factory()->create(['customer_code' => '482917']);

    $this->actingAs($owner, 'merchant');

    $this->postJson('/api/merchant/credits', [
        'customer_code' => '482917',
        'invoice_no' => 'INV-1',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0);
})->with(['draft', 'pending_review', 'rejected']);

it('refuses /v1 ingestion outright for never-approved merchants — no record-as-ineligible leniency', function (string $status) {
    $owner = pendingMerchantOwner();
    $merchant = $owner->merchant;
    $merchant->update(['status' => $status]);
    Customer::factory()->create(['customer_code' => '482917']);

    $token = $merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
        'Referer' => '',
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-1',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden_ability');

    expect(Transaction::query()->count())->toBe(0);
})->with(['draft', 'pending_review', 'rejected']);

it('still records a SUSPENDED merchant sale as ineligible — §7 leniency is for suspension only', function () {
    $owner = pendingMerchantOwner();
    $merchant = $owner->merchant;
    $merchant->update(['status' => 'suspended']);
    $this->seed(LedgerAccountSeeder::class);
    Customer::factory()->create(['customer_code' => '482917']);

    $token = $merchant->createToken('till', ['transactions:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => (string) Str::uuid(),
        'Referer' => '',
    ])->postJson('/api/v1/transactions', [
        'invoice_no' => 'INV-1',
        'customer_ref' => '482917',
        'eligible_amount' => 100000,
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertOk()
        ->assertJsonPath('status', 'recorded_ineligible');
});

it('gives a pending merchant no way to create a settlement', function () {
    $owner = pendingMerchantOwner();
    $this->actingAs($owner, 'merchant');

    // Receipt-first settlement mutations sit behind EnsureMerchantApproved:
    // a store still in review cannot claim a bank transfer against a batch
    // for trading it has not been approved to do. The refusal is the same
    // machine-readable conflict the wizard uses, not a validation error.
    $this->postJson('/api/merchant/settlements', [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_approved');

    $this->postJson('/api/merchant/settlements/wallet', ['settle_all' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_not_approved');

    // Reads stay open — there is simply nothing there.
    $this->getJson('/api/merchant/settlements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('enforces the channel enum at the database and drops is_online for good', function () {
    expect(Schema::hasColumn('merchants', 'channel'))->toBeTrue()
        ->and(Schema::hasColumn('merchants', 'is_online'))->toBeFalse();

    foreach (['in_store', 'online', 'both'] as $channel) {
        expect(Merchant::factory()->create(['channel' => $channel])->channel)->toBe($channel);
    }

    expect(fn () => Merchant::factory()->create(['channel' => 'omnichannel']))
        ->toThrow(QueryException::class);
});

it('backfills channel from the old is_online values', function () {
    // Recreate the pre-migration column, plant both legacy shapes, and
    // re-run the replacement migration — the CASE backfill must map
    // true → online and false → in_store. (Rows are created while `channel`
    // still exists; down() removes it, then the legacy bool is set.)
    $online = Merchant::factory()->create();
    $inStore = Merchant::factory()->create();

    $migration = require database_path('migrations/2026_08_15_010002_replace_is_online_with_channel_on_merchants_table.php');
    $migration->down();

    DB::table('merchants')->where('id', $online->id)->update(['is_online' => true]);
    DB::table('merchants')->where('id', $inStore->id)->update(['is_online' => false]);

    $migration->up();

    expect(DB::table('merchants')->where('id', $online->id)->value('channel'))->toBe('online')
        ->and(DB::table('merchants')->where('id', $inStore->id)->value('channel'))->toBe('in_store')
        ->and(Schema::hasColumn('merchants', 'is_online'))->toBeFalse();
});
