<?php

declare(strict_types=1);

use App\Domain\Money\Laari;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Domain\Transfers\SettlementPaymentVerifier;
use App\Models\Order;
use App\Models\PlatformBankAccount;
use App\Models\SettlementPayment;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * The other direction: merchants paying the platform.
 *
 * The difference that shapes this file — the merchant types their OWN
 * transfer reference into the slip. A reference we then find verbatim in our
 * history is proof of a kind a name match can never be, so it wins outright
 * and does not need the name to agree.
 */

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('services.transfer.api_key', 'test-key');

    $this->profile = TransferProfile::create([
        'name' => 'Cleviden',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet4',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);

    // The account merchants are TOLD to pay into.
    $this->ourAccount = PlatformBankAccount::query()->create([
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

    $builder = app(SettlementBuilder::class);
    $this->settlement = $builder->createDraft($this->merchant);
    $builder->submit($this->settlement);
    $this->settlement->refresh();
    $this->settlement->forceFill([
        'platform_bank_account_id' => $this->ourAccount->id,
    ])->save();
});

function settlementRow(string $name, int $laari, string $reference = '804802801', array $extra = []): void
{
    Http::fake(['*/faisanet4/history*' => Http::response(['data' => [array_merge([
        'trxNumber2' => $reference,
        'baseAmount' => $laari / 100,
        'absAmount' => $laari / 100,
        'benefName' => $name,
        'trxDate' => '2026-08-19 10:00:00',
    ], $extra)]])]);
}

function recordPayment(int $laari, ?string $bankRef): SettlementPayment
{
    return app(SettlementAllocator::class)->recordBankPayment(
        test()->settlement->refresh(),
        Laari::of($laari),
        $bankRef,
    );
}

it('matches on the reference the merchant typed, without needing the name', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    // A company transfer often arrives under an accountant's name or a bare
    // IPS label. The reference is what makes it provable anyway.
    settlementRow('SOME UNRELATED LABEL', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    $payment->refresh();
    expect($payment->state)->toBe('matched');
    expect($payment->auto_matched)->toBeTrue();
    expect($payment->matched_by_rule)->toBe('reference');
    // No admin decided this.
    expect($payment->matched_by)->toBeNull();
});

it('forgives spacing and hyphens in a retyped reference', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    // Somebody reading it off a banking app screen.
    $payment = recordPayment($due, '804-802 801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
    expect($payment->refresh()->matched_by_rule)->toBe('reference');
});

it('finds the short reference inside the bank\'s composite one', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '1-703337593-804802801-1');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
});

it('will not treat a scrap of a reference as evidence', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    // Too short to identify anything. A containment test on this would match
    // half the ledger.
    $payment = recordPayment($due, '801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('falls back to the name on the merchant\'s bank account', function (): void {
    // Not the trading name: "Agromart" is a shop, and the transfer arrives
    // from whoever owns the account.
    $this->merchant->forceFill(['bank_account_name' => 'Ahmed Nazeeh'])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, null);

    settlementRow('AHMD NAZEEH', $due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();
    expect($payment->refresh()->matched_by_rule)->toBe('name');
});

it('refuses a credit from a different payer with no reference', function (): void {
    $this->merchant->forceFill(['bank_account_name' => 'Ahmed Nazeeh'])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, null);

    settlementRow('MARIYAM SHIFA', $due);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    expect($payment->refresh()->state)->toBe('pending');
});

it('refuses when the amount is a laari out', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due - 1, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('ignores money going the wrong way', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    // A debit of the same amount is not a merchant paying us.
    settlementRow('WHOEVER', $due, '804802801', ['baseAmount' => -($due / 100)]);

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('never spends a credit already used to verify a customer order', function (): void {
    // One platform account takes both, so this collision is real rather
    // than theoretical.
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    Order::factory()->create([
        'total_payable_laari' => $due,
        'payment_state' => 'verified',
        'matched_trx_id' => '804802801',
    ]);

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('allocates through the same path an admin match uses', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeTrue();

    // Paid in full, so the batch settles — the allocator's own doing, not a
    // second version of it.
    expect($this->settlement->refresh()->state)->toBe(SettlementState::Settled);
    expect((int) $this->settlement->amount_received_laari)->toBe($due);
});

it('does nothing at all while the flag is off', function (): void {
    TransferSetting::current()->forceFill(['auto_verify_enabled' => false])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    Http::assertNothingSent();
});

it('does nothing when the account paid into is not watched', function (): void {
    $this->ourAccount->forceFill(['verify_profile_id' => null])->save();

    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
    Http::assertNothingSent();
});

it('leaves a payment an admin already matched alone', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');
    $payment->forceFill(['state' => 'matched'])->save();

    settlementRow('WHOEVER', $due, '804802801');

    expect(app(SettlementPaymentVerifier::class)->attempt($payment))->toBeFalse();
});

it('opens the bank-watching window when a payment is recorded', function (): void {
    $due = (int) $this->settlement->amount_due_laari;
    $payment = recordPayment($due, '804802801');

    expect($payment->poll_until)->not->toBeNull();
    expect($payment->poll_until->diffInMinutes($payment->poll_started_at, true))->toBe(15.0);
});
