<?php

declare(strict_types=1);

use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\WalletTopUps;
use App\Domain\Transfers\BankWatch;
use App\Models\AdminUser;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * "In case auto bank matching ever gets stuck" — the owner's reason for the
 * page, and the panel this file holds to it.
 *
 * The distinction that matters is not how many transfers are pending but how
 * many are pending WITH NOBODY LOOKING, and why. Every reason below is
 * BankWatch's own, staged exactly as TransferProgressTest stages it for the
 * merchant's screen, so the two surfaces cannot come to different answers
 * about the same row.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    Queue::fake();
    config()->set('services.transfer.api_key', 'test-key');

    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T06:00:00+00:00'));

    $this->admin = AdminUser::factory()->create(['role' => 'admin']);

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    // The account whose history we actually read...
    $this->watched = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $this->profile->id,
    ]);

    // ...and a real platform account nobody has a tunnel to.
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

    $this->fixture = SettlementFixture::payableBatch();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One settled-shaped batch of a single line, paid into $account, with its
 * watch window forced to $pollUntil ('none' leaves it null).
 */
function autoMatchPayment(int $lineIndex, int $accountId, CarbonImmutable|string|null $pollUntil): SettlementPayment
{
    $fixture = test()->fixture;
    $builder = app(SettlementBuilder::class);

    $settlement = $builder->submit($builder->createDraft($fixture->merchant, [$fixture->transactions[$lineIndex]->id]));
    $settlement->refresh()->forceFill(['platform_bank_account_id' => $accountId])->save();

    $payment = app(SettlementAllocator::class)->recordBankPayment(
        $settlement->refresh(),
        Laari::of($fixture->due($lineIndex)),
        'BML-WATCH-'.$lineIndex,
    );

    $payment->forceFill(['poll_until' => $pollUntil === 'none' ? null : $pollUntil])->save();

    return $payment;
}

function autoMatchTopUp(int $accountId, CarbonImmutable|string|null $pollUntil, string $bankRef): WalletTopUp
{
    $claim = app(WalletTopUps::class)->claim(
        test()->fixture->merchant,
        test()->fixture->user,
        Laari::of(50_000),
        $accountId,
        $bankRef,
        Slips::jpeg(),
    );

    $claim->forceFill(['poll_until' => $pollUntil === 'none' ? null : $pollUntil])->save();

    return $claim;
}

function autoMatchPanel(): array
{
    return test()->actingAs(test()->admin, 'admin')
        ->getJson('/api/admin/dashboard?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->json('auto_match');
}

it('separates what is being watched from what is waiting on a human, reason by reason', function (): void {
    $now = CarbonImmutable::now();

    // Watched right now: routed bank, window still open.
    autoMatchPayment(0, $this->watched->id, $now->addMinutes(10));
    // The window ran out three days ago — the admin queue owns it.
    autoMatchPayment(1, $this->watched->id, $now->subDays(3));
    // Uploaded while the switch was down: no job exists and none ever will.
    autoMatchPayment(2, $this->watched->id, 'none');
    // Paid into a bank nobody reads: a CONFIGURATION fault, not a wait.
    autoMatchPayment(3, $this->unwatched->id, $now->addMinutes(10));

    // The wallet flow, one of each of two reasons.
    autoMatchTopUp($this->watched->id, $now->addMinutes(10), '901901901');
    autoMatchTopUp($this->watched->id, $now->subHours(2), '901901902');

    $panel = autoMatchPanel();

    expect($panel['settlement_payments'])->toMatchArray([
        'pending_total' => 4,
        'watching_now' => 1,
        'waiting_on_human' => [
            'total' => 3,
            BankWatch::REASON_WINDOW_EXPIRED => 1,
            BankWatch::REASON_NEVER_WATCHED => 1,
            BankWatch::REASON_NO_VERIFY_PROFILE => 1,
            BankWatch::REASON_AUTO_VERIFY_OFF => 0,
        ],
        // Three days is not the last day.
        'expired_unmatched_24h' => 0,
    ]);

    expect($panel['wallet_top_ups'])->toMatchArray([
        'pending_total' => 2,
        'watching_now' => 1,
        'waiting_on_human' => [
            'total' => 1,
            BankWatch::REASON_WINDOW_EXPIRED => 1,
            BankWatch::REASON_NEVER_WATCHED => 0,
            BankWatch::REASON_NO_VERIFY_PROFILE => 0,
            BankWatch::REASON_AUTO_VERIFY_OFF => 0,
        ],
        // Two hours ago: exactly the window this number exists to surface.
        'expired_unmatched_24h' => 1,
    ]);
});

it('reports the whole board as auto_verify_off the moment the switch goes down', function (): void {
    $now = CarbonImmutable::now();

    autoMatchPayment(0, $this->watched->id, $now->addMinutes(10));
    autoMatchPayment(1, $this->watched->id, $now->subDays(3));
    autoMatchTopUp($this->watched->id, $now->addMinutes(10), '901901901');

    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $panel = autoMatchPanel();

    // Nothing is watched, and the reason is the switch — NOT window_expired,
    // which would say a check ran and found nothing.
    expect($panel['settlement_payments']['watching_now'])->toBe(0)
        ->and($panel['settlement_payments']['waiting_on_human'][BankWatch::REASON_AUTO_VERIFY_OFF])->toBe(2)
        ->and($panel['settlement_payments']['waiting_on_human'][BankWatch::REASON_WINDOW_EXPIRED])->toBe(0)
        ->and($panel['wallet_top_ups']['watching_now'])->toBe(0)
        ->and($panel['wallet_top_ups']['waiting_on_human'][BankWatch::REASON_AUTO_VERIFY_OFF])->toBe(1);
});

it('says no_verify_profile when the routed profile is switched off', function (): void {
    autoMatchPayment(0, $this->watched->id, CarbonImmutable::now()->addMinutes(10));

    // Mirrors SettlementPaymentVerifier::destination(): the profile must be
    // ACTIVE, or no history is read and nothing is watched.
    $this->profile->forceFill(['active' => false])->save();

    expect(autoMatchPanel()['settlement_payments'])->toMatchArray([
        'watching_now' => 0,
        'waiting_on_human' => [
            'total' => 1,
            BankWatch::REASON_WINDOW_EXPIRED => 0,
            BankWatch::REASON_NEVER_WATCHED => 0,
            BankWatch::REASON_NO_VERIFY_PROFILE => 1,
            BankWatch::REASON_AUTO_VERIFY_OFF => 0,
        ],
    ]);
});

it('shows the auto-versus-manual split so a falling auto rate is visible', function (): void {
    $allocator = app(SettlementAllocator::class);

    // Three matched payments in the period: two found by the verifier, one
    // reconciled by a person.
    $auto = [autoMatchPayment(0, $this->watched->id, 'none'), autoMatchPayment(1, $this->watched->id, 'none')];
    $manual = autoMatchPayment(2, $this->watched->id, 'none');

    foreach ($auto as $payment) {
        $allocator->matchPayment($payment, null);
        $payment->refresh()->forceFill(['auto_matched' => true])->save();
    }

    $allocator->matchPayment($manual, $this->admin);

    // A wallet claim matched by hand, and one still pending.
    $claim = autoMatchTopUp($this->watched->id, 'none', '901901901');
    app(WalletTopUps::class)->match($claim, $this->admin, null);
    autoMatchTopUp($this->watched->id, CarbonImmutable::now()->addMinutes(10), '901901902');

    $panel = autoMatchPanel();

    expect($panel['settlement_payments']['matched_in_period'])->toBe([
        'total' => 3,
        'auto' => 2,
        'manual' => 1,
        'auto_rate_percent' => '66.67',
    ])
        ->and($panel['settlement_payments']['pending_total'])->toBe(0)
        ->and($panel['wallet_top_ups']['matched_in_period'])->toBe([
            'total' => 1,
            'auto' => 0,
            'manual' => 1,
            'auto_rate_percent' => '0.00',
        ])
        ->and($panel['wallet_top_ups']['pending_total'])->toBe(1);
});

it('reports no rate at all when nothing matched, rather than nought per cent', function (): void {
    $panel = autoMatchPanel();

    // "0.00" would read as a stall; there is simply nothing to report.
    expect($panel['settlement_payments']['matched_in_period'])->toBe([
        'total' => 0,
        'auto' => 0,
        'manual' => 0,
        'auto_rate_percent' => null,
    ])
        ->and($panel['wallet_top_ups']['matched_in_period']['auto_rate_percent'])->toBeNull();
});

it('bounds the match split on BUSINESS midnight, not the UTC one', function (): void {
    $allocator = app(SettlementAllocator::class);
    $payment = autoMatchPayment(0, $this->watched->id, 'none');
    $allocator->matchPayment($payment, $this->admin);

    // 20:00 UTC on 31 July is 01:00 on 1 August in Malé. It is an August
    // match, and the August window must contain it.
    $payment->refresh()->forceFill(['matched_at' => CarbonImmutable::parse('2026-07-31T20:00:00+00:00')])->save();

    expect(autoMatchPanel()['settlement_payments']['matched_in_period']['total'])->toBe(1);

    // Two hours earlier is 23:00 on 31 July in Malé — July's, and out.
    $payment->refresh()->forceFill(['matched_at' => CarbonImmutable::parse('2026-07-31T18:00:00+00:00')])->save();

    expect(autoMatchPanel()['settlement_payments']['matched_in_period']['total'])->toBe(0);
});
