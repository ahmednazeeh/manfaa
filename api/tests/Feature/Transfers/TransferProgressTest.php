<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\WalletFunding;
use App\Domain\Settlement\WalletTopUps;
use App\Domain\Transfers\BankWatch;
use App\Jobs\PollSettlementPayment;
use App\Jobs\PollWalletTopUp;
use App\Models\AdminUser;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The status surface the two waiting screens observe (owner, 2026-08-25).
 *
 * The rule this file exists to hold: A SCREEN MAY NEVER ANIMATE PROGRESS
 * OVER NOTHING. `watching` is the server's own answer, computed from the
 * three facts the pollers themselves obey — the platform switch, a
 * destination bank actually routed to a read profile, a window still open —
 * plus the row still being pending. When any of them is false the payload
 * says so with a machine `reason`, and the client words it as "our team
 * will confirm your transfer shortly".
 *
 * Both flows answer the SAME shape from the same builder, on BOTH mounts.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    // Recording a payment and claiming a top-up both dispatch their watch
    // jobs after commit. Nothing here drives a poll; keep the queue out.
    Queue::fake();
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    // The account merchants are told to pay into, and whose history we read.
    $this->watched = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $this->profile->id,
    ]);

    // A second, real platform account that nobody reads the history of.
    // Not a fault — it is the ordinary state of a bank we have no tunnel
    // to — and a screen must never pretend it is being watched.
    $this->unwatched = PlatformBankAccount::query()->create([
        'bank_name' => 'bml',
        'account_no' => '7730000012345',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => false,
        'active' => true,
        'verify_profile_id' => null,
    ]);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
        'verify_min_score' => 60,
    ])->save();

    $this->admin = AdminUser::factory()->create();

    $this->fixture = SettlementFixture::payableBatch();
    $this->merchant = $this->fixture->merchant;
    $this->owner = $this->fixture->user;

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();
    $this->settlement->forceFill(['platform_bank_account_id' => $this->watched->id])->save();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The merchant's claimed transfer against the batch, with its watch open. */
function payment(int $laari = SettlementFixture::BATCH_DUE_LAARI, ?string $bankRef = '804802801'): SettlementPayment
{
    return app(SettlementAllocator::class)->recordBankPayment(
        test()->settlement->refresh(),
        Laari::of($laari),
        $bankRef,
    );
}

function topUp(int $laari = 50000, ?string $bankRef = '901901901', ?int $accountId = null): WalletTopUp
{
    return app(WalletTopUps::class)->claim(
        test()->merchant,
        test()->owner,
        Laari::of($laari),
        $accountId ?? test()->watched->id,
        $bankRef,
        Slips::jpeg(),
    );
}

function settlementProgressUrl(?int $id = null): string
{
    return '/api/merchant/settlements/'.($id ?? test()->settlement->id).'/payment-progress';
}

function topUpProgressUrl(int $id): string
{
    return '/api/merchant/wallet/top-ups/'.$id.'/progress';
}

/**
 * Bearer headers for a mobile merchant token.
 *
 * The mobile tree is bearer-ONLY (see EnsureMobileToken): a session user
 * arrives carrying a TransientToken and is refused with the same bare 401 as
 * a stranger. So a test that has already called actingAs() cannot then reach
 * /api/mobile — every mobile call in this file is made before any acting
 * user is set, or in a test of its own.
 */
function progressHeaders(MerchantUser $user): array
{
    return ['Authorization' => 'Bearer '.app(MobileTokenService::class)
        ->issue($user, MobileAudience::Merchant, 'Till')->plainTextToken];
}

function mobileSettlementProgressUrl(?int $id = null): string
{
    return '/api/mobile/v1/merchant/settlements/'.($id ?? test()->settlement->id).'/payment-progress';
}

function mobileTopUpProgressUrl(int $id): string
{
    return '/api/mobile/v1/merchant/wallet/top-ups/'.$id.'/progress';
}

/**
 * One mobile GET as $user.
 *
 * Guards are cached on the AuthManager for the life of the application, and
 * EnsureMobileToken sets the merchant guard's user in memory — so a SECOND
 * mobile request inside one test would otherwise be answered as the first
 * caller. Real HTTP never shares an application between requests; forgetting
 * the guards is how a test reproduces that.
 */
function mobileGet(string $url, MerchantUser $user)
{
    app('auth')->forgetGuards();

    return test()->getJson($url, progressHeaders($user));
}

/** A staff account holding exactly the named permissions, and nothing else. */
function staffHolding(Merchant $merchant, array $permissions, string $slug): MerchantUser
{
    $role = MerchantRole::query()->create([
        'merchant_id' => $merchant->id,
        'name' => $slug,
        'slug' => $slug,
        'permissions' => $permissions,
        'is_owner' => false,
        'is_system' => false,
    ]);

    return MerchantUser::factory()->for($merchant)->withRole($role)->create();
}

it('reports a live bank watch on a settlement payment, identically on both mounts', function (): void {
    $payment = payment();

    $expected = [
        'kind' => 'settlement_payment',
        'id' => $payment->id,
        'settlement_id' => $this->settlement->id,
        'state' => 'pending',
        'amount_laari' => SettlementFixture::BATCH_DUE_LAARI,
        'watching' => true,
        // Null exactly when watching is true — never both.
        'reason' => null,
        'attempts' => 0,
        'auto_matched' => false,
        'decided_at' => null,
        'outcome' => null,
    ];

    // Mobile first: the bearer tree refuses a request that already carries a
    // session user, so actingAs() must come after.
    $mobile = mobileGet(mobileSettlementProgressUrl(), $this->owner);
    $mobile->assertOk();

    $web = $this->actingAs($this->owner, 'merchant')->getJson(settlementProgressUrl());
    $web->assertOk();

    foreach ($expected as $key => $value) {
        $web->assertJsonPath("data.{$key}", $value);
    }

    // The window is the row's own, opened at record time and 15 minutes long.
    expect($web->json('data.watch_started_at'))->toBe($payment->poll_started_at->toIso8601String())
        ->and($web->json('data.watch_until'))->toBe($payment->poll_until->toIso8601String())
        // The server's clock travels with the answer so a handset counting
        // down to watch_until never has to trust its own.
        ->and($web->json('data.checked_at'))->not->toBeNull();

    // Byte for byte the same answer but for the moment it was read: one
    // payload, two mounts, no room to drift.
    expect(collect($mobile->json('data'))->except('checked_at')->all())
        ->toBe(collect($web->json('data'))->except('checked_at')->all());
});

it('reports a live bank watch on a wallet top-up, identically on both mounts', function (): void {
    $claim = topUp();

    $mobile = mobileGet(mobileTopUpProgressUrl($claim->id), $this->owner);
    $mobile->assertOk();

    $web = $this->actingAs($this->owner, 'merchant')->getJson(topUpProgressUrl($claim->id));

    $web->assertOk()
        ->assertJsonPath('data.kind', 'wallet_top_up')
        ->assertJsonPath('data.id', $claim->id)
        // The field stays in the shared shape and is null here: one client
        // parser reads both flows.
        ->assertJsonPath('data.settlement_id', null)
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.amount_laari', 50000)
        ->assertJsonPath('data.amount_mvr', '500.00')
        ->assertJsonPath('data.watching', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.attempts', 0)
        ->assertJsonPath('data.outcome', null);

    expect(collect($mobile->json('data'))->except('checked_at')->all())
        ->toBe(collect($web->json('data'))->except('checked_at')->all());
});

it('answers both flows in exactly the same shape', function (): void {
    $payment = payment();
    $claim = topUp();

    $this->actingAs($this->owner, 'merchant');

    $settlement = $this->getJson(settlementProgressUrl())->json('data');
    $wallet = $this->getJson(topUpProgressUrl($claim->id))->json('data');

    expect(array_keys($settlement))->toBe(array_keys($wallet))
        ->and(array_keys($settlement))->toBe([
            'kind', 'id', 'settlement_id', 'state', 'amount_laari', 'amount_mvr',
            'watching', 'reason', 'watch_started_at', 'watch_until', 'attempts',
            'auto_matched', 'decided_at', 'checked_at', 'outcome',
        ])
        ->and($payment->id)->toBeInt();
});

it('never lets one merchant read another store\'s transfer, and never confirms it exists', function (): void {
    $payment = payment();
    $claim = topUp();

    $other = Merchant::factory()->create();
    $intruder = MerchantUser::factory()->for($other)->owner()->create();

    // 404, not 403: a refusal that distinguished "not yours" from "no such
    // row" would let one store enumerate another's settlements.
    $this->actingAs($intruder, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertNotFound();

    $this->actingAs($intruder, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertNotFound();

    // Identical to the answer for a row that truly does not exist.
    $this->actingAs($intruder, 'merchant')
        ->getJson(settlementProgressUrl(999999))
        ->assertNotFound();

    $this->actingAs($intruder, 'merchant')
        ->getJson(topUpProgressUrl(999999))
        ->assertNotFound();

    // And the owner still reads their own.
    $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id);
});

it('scopes the mobile mount by the same ownership rule', function (): void {
    $claim = topUp();
    payment();

    $other = Merchant::factory()->create();
    $intruder = MerchantUser::factory()->for($other)->owner()->create();

    mobileGet(mobileSettlementProgressUrl(), $intruder)->assertNotFound();
    mobileGet(mobileTopUpProgressUrl($claim->id), $intruder)->assertNotFound();

    // Identical to a row that truly does not exist.
    mobileGet(mobileSettlementProgressUrl(999999), $intruder)->assertNotFound();
    mobileGet(mobileTopUpProgressUrl(999999), $intruder)->assertNotFound();
});

it('gates each read on the permission its parent read carries', function (): void {
    payment();
    $claim = topUp();

    // settlements.view alone: the batch's transfer, yes; the wallet, no.
    $settlementsOnly = staffHolding($this->merchant, [Permission::SettlementsView->value], 'settlements-only');

    $this->actingAs($settlementsOnly, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk();

    $this->actingAs($settlementsOnly, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'wallet.view');

    // wallet.view alone: the mirror image.
    $walletOnly = staffHolding($this->merchant, [Permission::WalletView->value], 'wallet-only');

    $this->actingAs($walletOnly, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertOk();

    $this->actingAs($walletOnly, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_required')
        ->assertJsonPath('permission', 'settlements.view');

    // A till account holding neither is refused on both.
    $till = staffHolding($this->merchant, [Permission::CreditsCreate->value], 'till-only');

    $this->actingAs($till, 'merchant')->getJson(settlementProgressUrl())->assertForbidden();
    $this->actingAs($till, 'merchant')->getJson(topUpProgressUrl($claim->id))->assertForbidden();
});

it('applies the same permissions on the mobile mount', function (): void {
    payment();
    $claim = topUp();

    // A till account: may take a payment, may not read the money screens.
    $till = staffHolding($this->merchant, [Permission::CreditsCreate->value], 'till-only');

    mobileGet(mobileSettlementProgressUrl(), $till)->assertForbidden();
    mobileGet(mobileTopUpProgressUrl($claim->id), $till)->assertForbidden();

    // The owner holds both — proving the refusals above are the gate doing
    // its job and not the route simply being unreachable.
    mobileGet(mobileSettlementProgressUrl(), $this->owner)->assertOk();
    mobileGet(mobileTopUpProgressUrl($claim->id), $this->owner)->assertOk();
});

it('refuses a stranger outright', function (): void {
    payment();

    $this->getJson(settlementProgressUrl())->assertUnauthorized();
    $this->getJson(mobileSettlementProgressUrl())->assertUnauthorized();
});

it('says auto_verify_off, and watches nothing, when the platform switch is down', function (): void {
    // Switched off BEFORE the claim, exactly as shipping dark looks: no job
    // is dispatched — and therefore NO WINDOW is stamped on the row either.
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $payment = payment();
    $claim = topUp();

    Queue::assertNothingPushed();

    expect($payment->refresh()->poll_until)->toBeNull()
        ->and($payment->poll_started_at)->toBeNull()
        ->and($claim->refresh()->poll_until)->toBeNull();

    $this->actingAs($this->owner, 'merchant');

    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_AUTO_VERIFY_OFF)
        // No window is reported, because there is none: a stamped fifteen
        // minutes with no job behind it is exactly what makes a screen draw
        // a bar over nothing.
        ->assertJsonPath('data.watch_until', null)
        ->assertJsonPath('data.watch_started_at', null)
        ->assertJsonPath('data.attempts', 0)
        ->assertJsonPath('data.outcome', null);

    $this->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_AUTO_VERIFY_OFF)
        ->assertJsonPath('data.watch_until', null);
});

it('never claims a watch on a transfer that arrived while the switch was down, once it comes back up', function (): void {
    // THE GO-LIVE MOMENT, and the exact false progress bar this round
    // exists to prevent: a transfer uploaded dark, and an admin turning
    // auto-verification on a minute later. No job was ever dispatched for
    // that row and none ever will be — the screen must say so.
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $payment = payment();
    $claim = topUp();

    Queue::assertNothingPushed();

    TransferSetting::current()->forceFill(['auto_verify_enabled' => true])->save();

    $this->actingAs($this->owner, 'merchant');

    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id)
        ->assertJsonPath('data.watching', false)
        // NOT window_expired: that reason is worded to the merchant as "the
        // automatic check ran and did not find your transfer", and here it
        // never ran at all.
        ->assertJsonPath('data.reason', BankWatch::REASON_NEVER_WATCHED)
        ->assertJsonPath('data.outcome', null);

    $this->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_NEVER_WATCHED);
});

it('puts the pollers back on still-open windows when auto-verification comes back on', function (): void {
    // Switching the platform switch off does not pause a poll chain, it ENDS
    // it: PollSettlementPayment returns without re-dispatching. So a row
    // armed while the switch was up, and left mid-window while it was down,
    // has nothing behind it — and `watching` would otherwise be true over a
    // bank nobody is reading. Switching back on restarts them.
    $payment = payment();
    $claim = topUp();

    expect($payment->refresh()->poll_until)->not->toBeNull();

    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    // A row uploaded while dark: never armed, and NOT adopted by the resume
    // — the team is already handling it, and starting its fifteen minutes
    // now would be a clock the merchant never saw the start of.
    $dark = topUp(bankRef: '901901902');
    expect($dark->refresh()->poll_until)->toBeNull();

    $superadmin = AdminUser::factory()->create(['role' => 'superadmin']);

    Queue::fake();

    $this->actingAs($superadmin, 'admin')
        ->patchJson('/api/admin/transfer-settings', ['auto_verify_enabled' => true])
        ->assertOk();

    // Exactly the two armed rows: the dark claim is left to the team.
    Queue::assertPushed(PollSettlementPayment::class, 1);
    Queue::assertPushed(PollWalletTopUp::class, 1);
});

it('says no_verify_profile when the money went to a bank nobody reads', function (): void {
    $payment = payment();
    $claim = topUp(accountId: $this->unwatched->id);

    // The batch was paid into the unwatched account too.
    $this->settlement->forceFill(['platform_bank_account_id' => $this->unwatched->id])->save();

    $this->actingAs($this->owner, 'merchant');

    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_NO_VERIFY_PROFILE)
        ->assertJsonPath('data.id', $payment->id);

    $this->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_NO_VERIFY_PROFILE);
});

it('says no_verify_profile when the account names a profile that has been switched off', function (): void {
    // Mirrors SettlementPaymentVerifier::destination(): the profile must be
    // ACTIVE. A deactivated one reads no history, so nothing is watched.
    $claim = topUp();
    $this->profile->forceFill(['active' => false])->save();

    $this->actingAs($this->owner, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_NO_VERIFY_PROFILE);
});

it('says window_expired once the watch window has lapsed', function (): void {
    $payment = payment();
    $claim = topUp();

    $lapsed = Carbon::now()->subMinute();
    $payment->forceFill(['poll_until' => $lapsed, 'poll_attempts' => 17])->save();
    $claim->forceFill(['poll_until' => $lapsed, 'poll_attempts' => 20])->save();

    $this->actingAs($this->owner, 'merchant');

    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_WINDOW_EXPIRED)
        // Seventeen real looks at the bank found nothing. The screen has to
        // stop the bar and hand over to the team, not keep spinning.
        ->assertJsonPath('data.attempts', 17)
        ->assertJsonPath('data.outcome', null);

    $this->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_WINDOW_EXPIRED)
        ->assertJsonPath('data.attempts', 20);
});

it('reports a batch paid off in full as settled, with nothing owed', function (): void {
    $payment = payment();

    app(SettlementAllocator::class)->matchPayment($payment, $this->admin);

    $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.state', 'matched')
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_TERMINAL)
        ->assertJsonPath('data.outcome.result', 'settled')
        ->assertJsonPath('data.outcome.settlement_state', 'settled')
        ->assertJsonPath('data.outcome.reference', $this->settlement->refresh()->reference)
        ->assertJsonPath('data.outcome.amount_received_laari', SettlementFixture::BATCH_DUE_LAARI)
        ->assertJsonPath('data.outcome.amount_received_mvr', '118.25')
        ->assertJsonPath('data.outcome.amount_outstanding_laari', 0)
        ->assertJsonPath('data.outcome.rejected_reason', null)
        // A hand-matched payment is not the bank's own finding, and the
        // screen may legitimately say so differently.
        ->assertJsonPath('data.auto_matched', false)
        ->assertJsonPath('data.decided_at', $payment->refresh()->matched_at->toIso8601String());
});

it('tells the truth about a batch that matched but only PARTLY settled', function (): void {
    // §4 line dues in allocation order: 2750, 1375, 5500, 2200. A transfer
    // of 4125 covers the first two whole lines and no more — §7 allocates
    // whole lines only.
    $payment = payment(4125, 'BML-PART-1');

    app(SettlementAllocator::class)->matchPayment($payment, $this->admin);

    $response = $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk();

    // The whole point of the round: "matched" must not be printed as
    // "done". The merchant still owes 7,700 laari on this batch and the
    // screen has to say the number.
    $response
        ->assertJsonPath('data.state', 'matched')
        ->assertJsonPath('data.outcome.result', 'partially_settled')
        ->assertJsonPath('data.outcome.settlement_state', 'partially_settled')
        ->assertJsonPath('data.outcome.amount_received_laari', 4125)
        ->assertJsonPath('data.outcome.amount_outstanding_laari', 7700)
        ->assertJsonPath('data.outcome.amount_outstanding_mvr', '77.00');

    expect($response->json('data.outcome.result'))->not->toBe('settled');
});

it('still owes the whole remainder once the parked wallet money has been spent elsewhere', function (): void {
    // 4,000 covers the first line (2,750) whole and parks 1,250 in the
    // wallet — money that belongs to the merchant and is spendable anywhere.
    $payment = payment(4000, 'BML-PARK-1');

    app(SettlementAllocator::class)->matchPayment($payment, $this->admin);

    $this->actingAs($this->owner, 'merchant');

    // While it is still in the wallet it really is funding for this batch,
    // so the further transfer that finishes it is 9,075 − 1,250.
    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.outcome.result', 'partially_settled')
        ->assertJsonPath('data.outcome.amount_received_laari', 4000)
        ->assertJsonPath('data.outcome.amount_outstanding_laari', 7825);

    // The hourly auto-settler, or the merchant's own settle-from-wallet
    // button, spends it on another batch. The lines this batch is still
    // holding did not get any smaller.
    app(WalletFunding::class)->debit(
        $this->merchant,
        1250,
        'settlement',
        'settlement',
        $this->settlement->id,
        'Spent on another batch',
    );

    $this->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.outcome.result', 'partially_settled')
        // due − received would still say 7,825 and be 1,250 short of the
        // truth: nothing but a fresh transfer of 9,075 finishes this batch.
        ->assertJsonPath('data.outcome.amount_outstanding_laari', 9075)
        ->assertJsonPath('data.outcome.amount_outstanding_mvr', '90.75');
});

it('never reports nothing owed on a batch that is not settled', function (): void {
    // The self-contradiction the old subtraction produced: received reaches
    // due (4,000 + 7,825) while three lines are still unallocated, and the
    // clamp said "still owed MVR 0.00" — with both clients then telling the
    // merchant to transfer exactly that to finish it.
    $first = payment(4000, 'BML-CLAMP-1');
    app(SettlementAllocator::class)->matchPayment($first, $this->admin);

    // The 1,250 parked by the first match goes elsewhere.
    app(WalletFunding::class)->debit(
        $this->merchant,
        1250,
        'settlement',
        'settlement',
        $this->settlement->id,
        'Spent on another batch',
    );

    $second = app(SettlementAllocator::class)->recordBankPayment(
        $this->settlement->refresh(),
        Laari::of(7825),
        'BML-CLAMP-2',
    );
    app(SettlementAllocator::class)->matchPayment($second, $this->admin);

    $response = $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.outcome.amount_received_laari', 11825);

    $outcome = $response->json('data.outcome');

    if ($outcome['result'] === 'partially_settled') {
        expect($outcome['amount_outstanding_laari'])->toBeGreaterThan(0);
    } else {
        expect($outcome['result'])->toBe('settled')
            ->and($outcome['amount_outstanding_laari'])->toBe(0);
    }
});

it('reports a refused receipt with the reason, and nothing owed on a cancelled batch', function (): void {
    $payment = payment();

    app(SettlementBuilder::class)->reject(
        $this->settlement->refresh(),
        $this->admin,
        'The slip shows a transfer to another bank.',
    );

    $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id)
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_TERMINAL)
        ->assertJsonPath('data.outcome.result', 'rejected')
        ->assertJsonPath('data.outcome.settlement_state', 'cancelled')
        ->assertJsonPath('data.outcome.rejected_reason', 'The slip shows a transfer to another bank.')
        // The batch is cancelled and its lines released. Printing its due
        // as outstanding would send the merchant to transfer money against
        // a reference that no longer accepts one.
        ->assertJsonPath('data.outcome.amount_outstanding_laari', 0)
        ->assertJsonPath('data.outcome.amount_received_laari', 0);
});

it('reports a credited top-up as the amount added and the balance now', function (): void {
    $claim = topUp();

    app(WalletTopUps::class)->match($claim, $this->admin, '901901901');

    $this->actingAs($this->owner, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.state', 'matched')
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_TERMINAL)
        ->assertJsonPath('data.outcome.result', 'credited')
        // The sentence the screen prints: "MVR 500.00 added, balance now
        // MVR 500.00".
        ->assertJsonPath('data.outcome.credited_laari', 50000)
        ->assertJsonPath('data.outcome.credited_mvr', '500.00')
        ->assertJsonPath('data.outcome.balance_laari', 50000)
        ->assertJsonPath('data.outcome.balance_mvr', '500.00')
        ->assertJsonPath('data.outcome.rejected_reason', null);
});

it('adds a credited top-up to whatever the wallet already held', function (): void {
    app(WalletTopUps::class)->match(topUp(20000, 'FIRST-1'), $this->admin, 'FIRST-1');
    $second = topUp(50000, 'SECOND-2');

    app(WalletTopUps::class)->match($second, $this->admin, 'SECOND-2');

    $this->actingAs($this->owner, 'merchant')
        ->getJson(topUpProgressUrl($second->id))
        ->assertOk()
        ->assertJsonPath('data.outcome.credited_laari', 50000)
        // The RESULTING balance, not this credit's amount.
        ->assertJsonPath('data.outcome.balance_laari', 70000);
});

it('reports a refused top-up with its reason and no credit', function (): void {
    $claim = topUp();

    app(WalletTopUps::class)->reject($claim, $this->admin, 'No matching credit on the statement.');

    $this->actingAs($this->owner, 'merchant')
        ->getJson(topUpProgressUrl($claim->id))
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.watching', false)
        ->assertJsonPath('data.reason', BankWatch::REASON_TERMINAL)
        ->assertJsonPath('data.outcome.result', 'rejected')
        ->assertJsonPath('data.outcome.credited_laari', 0)
        ->assertJsonPath('data.outcome.balance_laari', 0)
        ->assertJsonPath('data.outcome.rejected_reason', 'No matching credit on the statement.');
});

it('watches the newest receipt on a batch that has taken several', function (): void {
    $first = payment(4125, 'BML-PART-1');
    app(SettlementAllocator::class)->matchPayment($first, $this->admin);

    $second = app(SettlementAllocator::class)->recordBankPayment(
        $this->settlement->refresh(),
        Laari::of(7700),
        'BML-PART-2',
    );

    // The merchant is looking at the slip they just sent, not last week's.
    $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertOk()
        ->assertJsonPath('data.id', $second->id)
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.watching', true)
        ->assertJsonPath('data.amount_laari', 7700)
        ->assertJsonPath('data.outcome', null);
});

it('is a plain 404 on a batch that never claimed a transfer', function (): void {
    // Settled from the wallet: no slip, no bank payment, nothing to watch.
    // Indistinguishable from a batch belonging to somebody else.
    $this->actingAs($this->owner, 'merchant')
        ->getJson(settlementProgressUrl())
        ->assertNotFound();
});

it('stays cheap enough to poll every five seconds', function (): void {
    $payment = payment();
    $claim = topUp();

    // The permission gate resolves the role; pre-load it so what is counted
    // below is the endpoint's own cost and nothing else.
    $this->actingAs($this->owner->load('role'), 'merchant');

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    $this->getJson(settlementProgressUrl())->assertOk();

    // settlement_payments → settlements → transfer_settings →
    // platform_bank_accounts (with the profile's active check folded into
    // the same statement). Four primary-key reads, no relation eager-loaded,
    // no collection rendered. The payment is read FIRST so a match landing
    // between the two cannot pair a decided payment with a stale batch.
    expect($count)->toBe(4);

    $count = 0;
    $this->getJson(topUpProgressUrl($claim->id))->assertOk();

    // wallet_top_ups → transfer_settings → platform_bank_accounts.
    expect($count)->toBe(3);

    // A terminal read is cheaper still: no watch to evaluate.
    app(SettlementAllocator::class)->matchPayment($payment, $this->admin);

    $count = 0;
    $this->getJson(settlementProgressUrl())->assertOk();

    expect($count)->toBe(2);

    app(WalletTopUps::class)->reject($claim->refresh(), $this->admin, 'Not found.');

    $count = 0;
    $this->getJson(topUpProgressUrl($claim->id))->assertOk();

    // wallet_top_ups → merchant_wallets (the balance the screen prints).
    expect($count)->toBe(2);
});

it('keeps its own throttle bucket on every mount', function (): void {
    $paths = [
        'api/merchant/settlements/{id}/payment-progress',
        'api/merchant/wallet/top-ups/{id}/progress',
        'api/mobile/v1/merchant/settlements/{id}/payment-progress',
        'api/mobile/v1/merchant/wallet/top-ups/{id}/progress',
    ];

    foreach ($paths as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === $uri);

        expect($route)->not->toBeNull("route {$uri} is not mounted");

        // 120/min against a 5-second poll's 12/min: an order of magnitude of
        // headroom for a merchant with the panel and the app open at once,
        // and still a ceiling. ThrottlePerRoute gives each declaration its
        // own bucket, so this number is not shared with any other route.
        expect($route->gatherMiddleware())->toContain('throttle:120,1');
    }
});
