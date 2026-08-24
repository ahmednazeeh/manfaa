<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Settlement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A merchant with real payable transactions, built through the real credit
 * path (ManualCreditService → the state machine) so the accrual journals,
 * the events and the clock all exist exactly as they do in production. The
 * reports read those tables; a fixture that inserted rows directly would
 * prove nothing about them.
 *
 * §4 pricing throughout: 200bp cashback, 75bp fee. Every fixture names its
 * own merchant and customer, so two of them can stand side by side in one
 * test without colliding on a unique code.
 *
 * Not a *Test.php file — PHPUnit never collects it; tests reach it through
 * the Tests\ PSR-4 map.
 */
final class ReportFixture
{
    /** Every fixture's day 0, in UTC. Business time is five hours ahead. */
    public const string BASE = '2026-08-04T06:00:00+00:00';

    public Merchant $merchant;

    public MerchantUser $user;

    public Customer $customer;

    /** @var list<Transaction> in due-date (= allocation) order */
    public array $transactions = [];

    /**
     * @param  list<int>  $eligibles  eligible laari per sale, in the order they occur
     */
    public static function payable(
        array $eligibles,
        ?int $discountRateBp = null,
        ?string $merchantName = null,
        ?CarbonImmutable $base = null,
        int $minEligibleLaari = 5000,
    ): self {
        $base ??= CarbonImmutable::parse(self::BASE);

        if ($discountRateBp !== null) {
            app(PlatformConfig::class)->set('prompt_discount_rate_bp', $discountRateBp);
        }

        $fixture = new self;

        $fixture->merchant = Merchant::factory()->create([
            'name' => $merchantName ?? ('Report Shop '.Str::upper(Str::random(4))),
            'validation_window_days' => 3,
            'min_eligible_laari' => $minEligibleLaari,
        ]);

        MerchantRate::factory()->for($fixture->merchant)->create([
            'rate_bp' => 200,
            'effective_from' => $base->subYear(),
            'effective_to' => null,
        ]);

        $fixture->user = MerchantUser::factory()->for($fixture->merchant)->owner()->create();
        $fixture->customer = self::customer();

        $credits = app(ManualCreditService::class);
        $transitions = app(TransitionService::class);

        foreach ($eligibles as $index => $eligible) {
            // Credited minutes apart and made payable minutes apart, so the
            // due dates — and with them the §7 allocation order — are
            // deterministic.
            Carbon::setTestNow($base->addMinutes($index));

            $transaction = $credits->credit(
                $fixture->merchant,
                $fixture->user,
                $fixture->customer->customer_code,
                'INV-'.Str::upper(Str::random(8)),
                Laari::of($eligible),
                null,
                CarbonImmutable::now('UTC')->subHour(),
            );

            Carbon::setTestNow($base->addDays(3)->addMinutes($index));
            $transitions->makePayable($transaction, Actor::system());

            $fixture->transactions[] = $transaction->refresh();
        }

        // One day after the last line became payable: nothing is old, and
        // nothing is overdue.
        Carbon::setTestNow($base->addDays(4));

        return $fixture;
    }

    /** A customer with the §13 bank details a payout batch requires. */
    public static function customer(?string $name = null): Customer
    {
        return Customer::factory()->create([
            'name' => $name ?? 'Aishath Nizama',
            'customer_code' => (string) fake()->unique()->numberBetween(100000, 899999),
            'payout_bank' => 'bml',
            'payout_account' => (string) fake()->unique()->numberBetween(7730000000000, 7739999999999),
            'payout_account_name' => $name ?? 'Aishath Nizama',
        ]);
    }

    /** Builds and submits a settle-everything batch — where the discount is decided. */
    public function submit(): Settlement
    {
        $builder = app(SettlementBuilder::class);

        return $builder->submit($builder->createDraft($this->merchant))->refresh();
    }

    /** Records a bank transfer against the batch and matches it as an admin. */
    public function payAndMatch(Settlement $settlement, int $amountLaari, ?AdminUser $admin = null): Settlement
    {
        $allocator = app(SettlementAllocator::class);

        $payment = $allocator->recordBankPayment(
            $settlement->refresh(),
            Laari::of($amountLaari),
            'BML-'.Str::upper(Str::random(10)),
        );

        return $allocator->matchPayment($payment, $admin ?? AdminUser::factory()->create())->refresh();
    }

    /** A line's due from its own §4 integers. */
    public function due(int $index): int
    {
        $transaction = $this->transactions[$index];

        return $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;
    }

    public function dueTotal(): int
    {
        $total = 0;

        foreach (array_keys($this->transactions) as $index) {
            $total += $this->due($index);
        }

        return $total;
    }

    /**
     * @return list<int>
     */
    public function transactionIds(): array
    {
        return array_map(static fn (Transaction $transaction): int => $transaction->id, $this->transactions);
    }
}
