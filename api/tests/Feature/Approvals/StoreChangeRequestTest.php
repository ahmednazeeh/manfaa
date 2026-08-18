<?php

declare(strict_types=1);

use App\Domain\Approvals\ChangeRequestException;
use App\Domain\Approvals\ChangeRequestService;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Jobs\SendPushNotification;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantChangeRequest;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Approvals;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * MR9 — admin approval for store edits and new branches, merchant side.
 *
 * The line the owner drew is CLAIMS vs OPERATIONS: what a shopper reads and
 * trusts (name, Dhivehi name, category, channel, the "what earns cashback"
 * promise, website, logo, and the branch estate) queues for review; the
 * contact numbers, which are corrective rather than a claim, never wait.
 *
 * The gate lives in the shared controllers, so these assertions hold on the
 * web panel's mount and the merchant app's alike — the mobile section at the
 * bottom proves the app meets the same contract through its envelope.
 */
beforeEach(function () {
    $this->merchant = Merchant::factory()->create([
        'name' => 'Original Name',
        'category' => 'grocery',
        'channel' => 'in_store',
        'contact_phone' => '+9607000001',
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();

    $this->actingAs($this->owner, 'merchant');
});

// --------------------------------------------------------- profile gating

it('queues a gated profile field and leaves the merchant row untouched', function () {
    $response = $this->patchJson('/api/merchant/profile', [
        'name' => 'Chai House',
        'eligibility_basis' => 'Everything except tobacco.',
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_review')
        ->assertJsonPath('data.change_request.kind', 'profile')
        ->assertJsonPath('data.change_request.status', 'pending')
        ->assertJsonPath('data.change_request.proposed.name', 'Chai House')
        ->assertJsonPath('data.change_request.current.name', 'Original Name');

    // The before/after the reviewer decides on, field by field.
    expect($response->json('data.change_request.changes'))->toEqualCanonicalizing([
        ['field' => 'name', 'from' => 'Original Name', 'to' => 'Chai House'],
        ['field' => 'eligibility_basis', 'from' => $this->merchant->eligibility_basis, 'to' => 'Everything except tobacco.'],
    ]);

    // Nothing a shopper reads has moved.
    expect($this->merchant->refresh()->name)->toBe('Original Name')
        ->and($this->merchant->eligibility_basis)->not->toBe('Everything except tobacco.');
});

it('applies the instant contact keys in the very request that queues the claims', function () {
    // A wrong number means customers cannot reach the store, so reviewing
    // the fix would only prolong the harm (§MR9). Both halves are honoured.
    $this->patchJson('/api/merchant/profile', [
        'name' => 'Chai House',
        'contact_email' => 'owner@store.mv',
        'contact_phone' => '+9607779999',
    ])->assertStatus(202)
        ->assertJsonPath('data.profile.contact_email', 'owner@store.mv')
        ->assertJsonPath('data.profile.contact_phone', '+9607779999')
        ->assertJsonPath('data.profile.name', 'Original Name');

    $merchant = $this->merchant->refresh();

    expect($merchant->contact_email)->toBe('owner@store.mv')
        ->and($merchant->contact_phone)->toBe('+9607779999')
        ->and($merchant->name)->toBe('Original Name');
});

it('applies an instant-only write with no review and no 202', function () {
    $this->patchJson('/api/merchant/profile', ['support_phone' => '+9603334455'])
        ->assertOk()
        ->assertJsonPath('data.support_phone', '+9603334455');

    expect(MerchantChangeRequest::query()->count())->toBe(0);
});

it('lets a pre-approval store write straight through — the onboarding wizard must not deadlock', function (string $status) {
    // The wizard is a draft store's ONLY write path, and the whole record is
    // about to be reviewed anyway. Gating it would park a store's own setup
    // behind a queue that reviews the store, not its edits.
    $draft = Merchant::factory()->create(['status' => $status, 'name' => 'Half Filled', 'setup_state' => []]);
    $owner = MerchantUser::factory()->for($draft)->owner()->create();

    $this->actingAs($owner, 'merchant')
        ->patchJson('/api/merchant/setup/profile', [
            'category' => 'cafe',
            'channel' => 'both',
            'eligibility_basis' => 'Food and drink.',
        ])->assertOk();

    expect($draft->refresh()->category)->toBe('cafe')
        ->and($draft->channel)->toBe('both')
        ->and(MerchantChangeRequest::query()->count())->toBe(0);

    // And the wizard's branch pin — the location step — writes directly too.
    $this->actingAs($owner, 'merchant')
        ->patchJson('/api/merchant/setup/location', ['lat' => 4.1755, 'lng' => 73.5093])
        ->assertOk();

    expect($draft->branches()->count())->toBe(1)
        ->and(MerchantChangeRequest::query()->count())->toBe(0);
})->with(['draft', 'rejected']);

// ---------------------------------------------------------------- branches

it('queues branch create, update and delete, and applies each on approval', function () {
    $this->postJson('/api/merchant/branches', ['name' => 'Hulhumalé', 'lat' => 4.2105, 'lng' => 73.5409])
        ->assertStatus(202)
        ->assertJsonPath('data.change_request.kind', 'branch_create');

    expect(MerchantBranch::query()->count())->toBe(0);

    Approvals::approveAll($this->merchant);

    $branch = MerchantBranch::query()->sole();
    expect($branch->name)->toBe('Hulhumalé')
        // The pin decides the island, on the approved write as on any other.
        ->and((float) $branch->lat)->toBe(4.2105);

    $this->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Hulhumalé Flagship'])
        ->assertStatus(202)
        ->assertJsonPath('data.change_request.branch_id', $branch->id)
        ->assertJsonPath('data.change_request.branch_name', 'Hulhumalé');

    expect($branch->refresh()->name)->toBe('Hulhumalé');

    Approvals::approveAll($this->merchant);
    expect($branch->refresh()->name)->toBe('Hulhumalé Flagship');

    $this->deleteJson("/api/merchant/branches/{$branch->id}")->assertStatus(202);
    expect(MerchantBranch::query()->count())->toBe(1);

    Approvals::approveAll($this->merchant);
    expect(MerchantBranch::query()->count())->toBe(0);

    // The approved removal survives its own branch: the FK nulls the column,
    // the snapshot keeps saying which branch it was.
    $decided = MerchantChangeRequest::query()->where('kind', 'branch_delete')->sole();
    expect($decided->status)->toBe('approved')
        ->and($decided->branch_id)->toBeNull()
        ->and($decided->snapshot['name'])->toBe('Hulhumalé Flagship');
});

it('keeps two queued new branches apart instead of superseding one with the other', function () {
    // "One pending request per type" exists so nobody is stuck behind their
    // own earlier request — and a second shop opening is not stuck behind
    // the first, it is a different place.
    $this->postJson('/api/merchant/branches', ['name' => 'Hulhumalé'])->assertStatus(202);
    $this->postJson('/api/merchant/branches', ['name' => 'Addu'])->assertStatus(202);

    expect(MerchantChangeRequest::query()->where('status', 'pending')->count())->toBe(2);

    Approvals::approveAll($this->merchant);

    expect(MerchantBranch::query()->pluck('name')->all())->toEqualCanonicalizing(['Hulhumalé', 'Addu']);
});

// -------------------------------------------------------------- supersede

it('supersedes the merchant\'s own earlier pending request rather than stacking', function () {
    $this->patchJson('/api/merchant/profile', ['name' => 'First Idea'])->assertStatus(202);
    $this->patchJson('/api/merchant/profile', ['name' => 'Second Idea'])->assertStatus(202);

    $rows = MerchantChangeRequest::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe('superseded')
        ->and($rows[1]->status)->toBe('pending')
        ->and($rows[1]->payload['name'])->toBe('Second Idea');

    Approvals::approveAll($this->merchant);

    expect($this->merchant->refresh()->name)->toBe('Second Idea');
});

it('carries a still-pending field forward across a superseding submission', function () {
    // The logo has its own endpoint but the same KIND, so without carry-
    // forward uploading one would silently drop a queued rename.
    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])->assertStatus(202);

    // A second save that only moves the website — the panel PATCHes the
    // whole form, so the name arrives back unchanged and is not re-proposed.
    $this->patchJson('/api/merchant/profile', [
        'name' => 'Original Name',
        'website_url' => 'chaihouse.mv',
    ])->assertStatus(202);

    $pending = MerchantChangeRequest::query()->where('status', 'pending')->sole();

    // (jsonb normalises key order, so the comparison cannot depend on it.)
    expect($pending->payload)->toEqualCanonicalizing([
        'website_url' => 'chaihouse.mv',
        'name' => 'Chai House',
    ]);
});

it('publishes proposed and current as JSON OBJECTS even when a removal proposes nothing', function () {
    // PHP writes an empty array as the JSON LIST `[]`, and a branch removal
    // is the one kind whose payload is empty by construction. Every typed
    // client reads `proposed`/`current` as a dictionary, so without the cast
    // the merchant panel's branch list, its delete call and the admin review
    // queue all refuse the very request they exist to show. Asserted on the
    // RAW body, because json_decode erases the distinction.
    $branch = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Doomed']);

    $delete = $this->deleteJson("/api/merchant/branches/{$branch->id}");

    expect($delete->getContent())->toContain('"proposed":{}')
        ->and($delete->getContent())->not->toContain('"proposed":[]');

    // And on every other surface the same row is published through.
    expect($this->getJson('/api/merchant/branches')->getContent())->toContain('"proposed":{}');

    $admin = AdminUser::factory()->create(['role' => 'superadmin']);
    expect($this->actingAs($admin, 'admin')->getJson('/api/admin/change-requests')->getContent())
        ->toContain('"proposed":{}');
});

it('leaves a pending request alone when the save moved only an instant key', function () {
    // The panels PATCH the WHOLE form, so a contact-number save arrives
    // carrying every gated field back at us unchanged. That must be a 200
    // with the queue untouched: if the carry-forward treated it as a
    // proposal, the row the owner is waiting on would be superseded and an
    // identical copy re-queued — resetting its submitted_at, its submitter
    // and its place in the reviewer's oldest-first queue every time anyone
    // fixed a phone number.
    $this->patchJson('/api/merchant/profile', ['name' => 'Queued Rename'])->assertStatus(202);
    $queued = MerchantChangeRequest::query()->sole();

    $this->patchJson('/api/merchant/profile', [
        'name' => 'Original Name',
        'category' => 'grocery',
        'channel' => 'in_store',
        'contact_phone' => '+9601112223',
    ])->assertOk();

    expect(MerchantChangeRequest::query()->count())->toBe(1)
        ->and($queued->refresh()->status)->toBe('pending')
        ->and($queued->payload)->toBe(['name' => 'Queued Rename'])
        ->and($queued->created_at->equalTo($queued->getOriginal('created_at')))->toBeTrue()
        // ...and the instant half still applied in that very request.
        ->and($this->merchant->refresh()->contact_phone)->toBe('+9601112223');
});

it('leaves a pending branch update alone when the branch dialog was re-saved untouched', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Majeedhee Magu']);

    $this->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Renamed'])->assertStatus(202);
    $queued = MerchantChangeRequest::query()->sole();

    $this->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Majeedhee Magu'])->assertOk();

    expect(MerchantChangeRequest::query()->count())->toBe(1)
        ->and($queued->refresh()->status)->toBe('pending')
        ->and($queued->payload)->toBe(['name' => 'Renamed']);
});

it('supersedes a queued branch rename with a queued removal of the same branch', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create(['name' => 'Majeedhee Magu']);

    $this->patchJson("/api/merchant/branches/{$branch->id}", ['name' => 'Renamed'])->assertStatus(202);
    $this->deleteJson("/api/merchant/branches/{$branch->id}")->assertStatus(202);

    $pending = MerchantChangeRequest::query()->where('status', 'pending')->sole();

    // Two answers to one question; the later one is the merchant's. And the
    // rename does NOT ride along into the removal's payload.
    expect($pending->kind->value)->toBe('branch_delete')
        ->and($pending->payload)->toBe([]);

    Approvals::approveAll($this->merchant);

    expect(MerchantBranch::query()->count())->toBe(0);
});

// ------------------------------------------------------ approve and reject

it('busts the discovery read model so the storefront serves the approved change', function () {
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200, 'effective_from' => now()->subDay(), 'effective_to' => null,
    ]);

    $discovery = app(DiscoveryService::class);

    // Warm the 60s store payload, as a real storefront read would.
    expect($discovery->store($this->merchant->slug)['name'])->toBe('Original Name');

    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])->assertStatus(202);

    // Still the old card while it waits — that is the point of the queue.
    expect($discovery->store($this->merchant->slug)['name'])->toBe('Original Name');

    Approvals::approveAll($this->merchant);

    expect($discovery->store($this->merchant->slug)['name'])->toBe('Chai House');
});

it('stores the reason on a rejection and changes nothing', function () {
    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])->assertStatus(202);

    $request = MerchantChangeRequest::query()->sole();
    $admin = Approvals::superadmin();

    app(ChangeRequestService::class)->reject($request, $admin, 'Your registered business name is different.');

    $request->refresh();

    expect($request->status)->toBe('rejected')
        ->and($request->rejected_reason)->toBe('Your registered business name is different.')
        ->and($request->reviewed_by)->toBe($admin->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($this->merchant->refresh()->name)->toBe('Original Name');
});

it('refuses to decide the same request twice', function () {
    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])->assertStatus(202);

    $request = MerchantChangeRequest::query()->sole();

    Approvals::approve($request);

    expect(fn () => Approvals::approve($request->refresh()))
        ->toThrow(ChangeRequestException::class);
});

it('refuses at approval a branch removal the history has caught up with', function () {
    $branch = MerchantBranch::factory()->for($this->merchant)->create();

    $this->deleteJson("/api/merchant/branches/{$branch->id}")->assertStatus(202);

    // The branch takes a sale while the removal sits in the queue.
    Transaction::factory()->create([
        'merchant_id' => $this->merchant->id,
        'branch_id' => $branch->id,
        'customer_id' => Customer::factory()->create()->id,
    ]);

    expect(fn () => Approvals::approve(MerchantChangeRequest::query()->sole()))
        ->toThrow(ChangeRequestException::class);

    expect(MerchantBranch::query()->whereKey($branch->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------- notifications

it('tells the store when a change is approved, and why when it is refused', function () {
    Queue::fake();

    // A till device for the owner — merchant moments are push-only.
    $token = app(MobileTokenService::class)->issue($this->owner, MobileAudience::Merchant, 'Till')->plainTextToken;
    DeviceToken::query()->create([
        'tokenable_type' => $this->owner->getMorphClass(),
        'tokenable_id' => $this->owner->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($token)->getKey(),
        'token' => 'till-'.Str::random(8),
        'platform' => 'android',
        'locale' => 'en',
    ]);

    $this->patchJson('/api/merchant/profile', ['name' => 'Chai House'])->assertStatus(202);
    Approvals::approve(MerchantChangeRequest::query()->sole());

    $read = fn (SendPushNotification $job, string $prop): string => (string) (new ReflectionProperty(SendPushNotification::class, $prop))->getValue($job);

    $pushes = collect(Queue::pushedJobs()[SendPushNotification::class] ?? [])
        ->map(fn (array $entry): array => [
            'key' => $read($entry['job'], 'templateKey'),
            'body' => $read($entry['job'], 'body'),
        ]);

    expect($pushes)->toHaveCount(1)
        ->and($pushes[0]['key'])->toBe('store_change_approved')
        ->and($pushes[0]['body'])->toBe('Your store profile change was approved and is now live on Manfaa.');

    $this->postJson('/api/merchant/branches', ['name' => 'Addu'])->assertStatus(202);
    app(ChangeRequestService::class)->reject(
        MerchantChangeRequest::query()->where('kind', 'branch_create')->sole(),
        Approvals::superadmin(),
        'We could not find that address.',
    );

    $rejected = collect(Queue::pushedJobs()[SendPushNotification::class] ?? [])
        ->map(fn (array $entry): array => [
            'key' => $read($entry['job'], 'templateKey'),
            'body' => $read($entry['job'], 'body'),
        ])->last();

    // The reason travels with it — a refusal the store cannot act on is not
    // a refusal, it is a wall.
    expect($rejected['key'])->toBe('store_change_rejected')
        ->and($rejected['body'])->toBe('Your new branch was not approved: We could not find that address.');
});
