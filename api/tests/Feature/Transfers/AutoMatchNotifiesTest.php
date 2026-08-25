<?php

declare(strict_types=1);

use App\Domain\MerchantAccess\Permission;
use App\Domain\Mobile\MobileAudience;
use App\Domain\Mobile\MobileTokenService;
use App\Domain\Money\Laari;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletTopUps;
use App\Domain\Transfers\SettlementPaymentVerifier;
use App\Domain\Transfers\WalletTopUpVerifier;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * "A push + SMS on match already exists for both — verify it" (owner,
 * 2026-08-25).
 *
 * The thing worth proving is not that an ADMIN's click tells the store —
 * that has always been covered. It is that the AUTOMATIC path tells them
 * too. Both verifiers reach the notification indirectly: the settlement one
 * through SettlementAllocator::matchPayment(payment, null), the wallet one
 * through WalletTopUps::credit(row, ref, null). Both notifications are
 * queued from inside a DB::afterCommit inside those transactions, and a
 * regression there would be silent — the money would move correctly and the
 * merchant would simply never hear.
 *
 * So these tests drive the VERIFIERS against a faked bank feed, and assert
 * the messages were queued, with the right numbers in them.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    $this->account = PlatformBankAccount::query()->create([
        'bank_name' => 'mib',
        'account_no' => '90501400021681001',
        'account_name' => 'Cleviden Pvt Ltd',
        'currency' => 'MVR',
        'is_primary' => true,
        'active' => true,
        'verify_profile_id' => $this->profile->id,
    ]);

    TransferSetting::current()->forceFill([
        'auto_verify_enabled' => true,
        'verify_window_minutes' => 15,
        'verify_min_score' => 60,
    ])->save();

    $this->fixture = SettlementFixture::payableBatch();
    $this->merchant = $this->fixture->merchant;
    $this->owner = $this->fixture->user;

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();
    $this->settlement->forceFill(['platform_bank_account_id' => $this->account->id])->save();

    // A till in the shop, so the push half has somewhere to land. The SMS
    // half goes to the merchant row's own contact number.
    $auth = app(MobileTokenService::class)->issue($this->owner, MobileAudience::Merchant, 'Till')->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $this->owner->getMorphClass(),
        'tokenable_id' => $this->owner->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)?->getKey(),
        'token' => 'till-device',
        'platform' => 'android',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** One incoming credit in our own history, with the reference the merchant quoted. */
function creditRow(int $laari, string $reference = '804802801', string $name = 'WHOEVER'): void
{
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => [[
        'trxNumber2' => $reference,
        'baseAmount' => $laari / 100,
        'absAmount' => $laari / 100,
        'benefName' => $name,
        'trxDate' => '2026-08-25 10:00:00',
    ]]])]);
}

function bankPayment(int $laari, string $bankRef): SettlementPayment
{
    return app(SettlementAllocator::class)->recordBankPayment(
        test()->settlement->refresh(),
        Laari::of($laari),
        $bankRef,
    );
}

function walletClaim(int $laari, string $bankRef): WalletTopUp
{
    return app(WalletTopUps::class)->claim(
        test()->merchant,
        test()->owner,
        Laari::of($laari),
        test()->account->id,
        $bankRef,
        Slips::jpeg(),
    );
}

/** The body of the one SMS queued, whatever it was. */
function queuedSmsBody(): string
{
    $body = '';

    Queue::assertPushed(SendCustomerSms::class, function (SendCustomerSms $job) use (&$body): bool {
        $body = (new ReflectionProperty($job, 'body'))->getValue($job);

        return true;
    });

    return $body;
}

it('has both moments live as shipped', function (): void {
    // If either template were switched off, the assertions below would pass
    // for the wrong reason — nothing sent, nothing to catch.
    foreach ([NotificationTemplateKey::SettlementAccepted, NotificationTemplateKey::WalletTopUpReceived] as $key) {
        expect(NotificationTemplate::query()->where('key', $key->value)->value('active'))->toBeTrue();
    }
});

it('tells the store when the BANK — not an admin — settles their batch', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = bankPayment($due, '804802801');

    Queue::fake();
    creditRow($due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();

    // The AUTO path, provably: the bank's own history matched it and no
    // person is recorded as having decided.
    expect($payment->state)->toBe('matched')
        ->and($payment->auto_matched)->toBeTrue()
        ->and($payment->matched_by)->toBeNull()
        ->and($this->settlement->refresh()->state)->toBe(SettlementState::Settled);

    // Push to the till, SMS to the store's number — the pair the owner
    // asked us to verify rather than rebuild.
    Queue::assertPushed(SendPushNotification::class, 1);
    Queue::assertPushed(SendCustomerSms::class, 1);

    // And it names the batch, so the template really rendered with the
    // variables matchPayment passed it.
    expect(queuedSmsBody())->toContain((string) $this->settlement->reference);
});

it('does NOT announce acceptance when the bank\'s money only partly settles the batch', function (): void {
    // §4 dues in allocation order are 2750, 1375, 5500, 2200. 4,125 covers
    // the first two lines whole and no more.
    $payment = bankPayment(4125, 'BML-PART-1');

    Queue::fake();
    creditRow(4125, 'BML-PART-1');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    expect($payment->refresh()->auto_matched)->toBeTrue()
        ->and($this->settlement->refresh()->state)->toBe(SettlementState::PartiallySettled);

    // "Settlement is paid off, thank you" would be a lie: 7,700 laari are
    // still owed. The progress screen says so instead.
    Queue::assertNotPushed(SendPushNotification::class);
    Queue::assertNotPushed(SendCustomerSms::class);
});

it('tells the store when the BANK — not an admin — credits their wallet', function (): void {
    $claim = walletClaim(50000, '901901901');

    Queue::fake();
    creditRow(50000, '901901901');

    expect(app(WalletTopUpVerifier::class)->attempt($claim))->toBeTrue();

    $claim->refresh();

    expect($claim->state)->toBe('matched')
        ->and($claim->auto_matched)->toBeTrue()
        ->and($claim->matched_by)->toBeNull()
        ->and($claim->wallet_transaction_id)->not->toBeNull();

    Queue::assertPushed(SendPushNotification::class, 1);
    Queue::assertPushed(SendCustomerSms::class, 1);

    // The message carries what the screen also prints: the amount that went
    // in, and the balance it produced.
    expect(queuedSmsBody())->toContain('500.00');
});

it('reaches only the staff who may act on the moment', function (): void {
    // A second account with no settlement permission gets no push — the
    // catalogue addresses these by permission, and this is the property the
    // auto path inherits from sendToMerchantStaff. (The shipped STAFF preset
    // does hold settlements.view, so the account is built by hand.)
    $cashier = MerchantUser::factory()->for($this->merchant)->withRole(
        MerchantRole::query()->create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Cashier',
            'slug' => 'cashier-'.$this->merchant->id,
            'permissions' => [Permission::CreditsCreate->value],
            'is_owner' => false,
            'is_system' => false,
        ])
    )->create();

    $auth = app(MobileTokenService::class)->issue($cashier, MobileAudience::Merchant, 'Till 2')->plainTextToken;

    DeviceToken::query()->create([
        'tokenable_type' => $cashier->getMorphClass(),
        'tokenable_id' => $cashier->getKey(),
        'personal_access_token_id' => PersonalAccessToken::findToken($auth)?->getKey(),
        'token' => 'cashier-device',
        'platform' => 'android',
    ]);

    $due = (int) $this->settlement->amount_due_laari;
    $payment = bankPayment($due, '804802801');

    Queue::fake();
    creditRow($due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    Queue::assertPushed(SendPushNotification::class, 1);
});
