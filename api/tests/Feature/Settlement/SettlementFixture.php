<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The §4 batch as live rows: four manual credits at 200bp, each walked to
 * payable_unfunded through the real credit path and state machine (accrual
 * journals posted, events written) — dues 2,750 / 1,375 / 5,500 / 2,200 in
 * due-date order, batch total 11,825 laari.
 *
 * The clock is left at BASE + 4 days ("today" for the test), one day after
 * the last transaction became payable, so every line sits in the 0–5 ageing
 * bucket and nothing is overdue. Not a *Test.php file — PHPUnit never
 * collects it; tests reach it through the Tests\ PSR-4 map.
 *
 * The PLAN §1 prompt-payment discount is pinned OFF here (rate 0bp) so this
 * fixture keeps pricing the §4 table exactly as §4 states it: every test
 * built on it is about allocation mechanics — partial payments, the
 * forgiveness boundary, overpayment, the wallet remainder — and a discount
 * silently shaving 162 laari off each of those would make every assertion
 * measure two rules at once. Tests that ARE about the discount turn it on
 * explicitly (see tests/Feature/PromptDiscount), and one of them settles this
 * same batch through a platform left at its shipped defaults, so the live
 * default is covered too.
 */
final class SettlementFixture
{
    public const string BASE = '2026-08-01T10:00:00+00:00';

    /** §4: eligible amounts whose 200bp + 75bp lines sum to 11,825. */
    public const array ELIGIBLES = [100000, 50000, 200000, 80000];

    public const int BATCH_DUE_LAARI = 11825;

    public Merchant $merchant;

    public MerchantUser $user;

    public Customer $customer;

    /** @var list<Transaction> in due-date (= allocation) order */
    public array $transactions = [];

    public static function payableBatch(string $customerCode = '482917'): self
    {
        $base = CarbonImmutable::parse(self::BASE);

        // See the class docblock: the §4 fixture prices the §4 table.
        app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

        $fixture = new self;
        $fixture->merchant = Merchant::factory()->create([
            'validation_window_days' => 3,
            'min_eligible_laari' => 5000,
        ]);
        MerchantRate::factory()->for($fixture->merchant)->create([
            'rate_bp' => 200,
            'effective_from' => $base->subYear(),
            'effective_to' => null,
        ]);
        $fixture->user = MerchantUser::factory()->for($fixture->merchant)->owner()->create();
        $fixture->customer = Customer::factory()->create(['customer_code' => $customerCode]);

        $credits = app(ManualCreditService::class);
        $transitions = app(TransitionService::class);

        foreach (self::ELIGIBLES as $index => $eligible) {
            // Credit at BASE + n minutes, make payable at BASE + 3d + n
            // minutes: due dates land strictly ascending in §4 line order.
            Carbon::setTestNow($base->addMinutes($index));

            $transaction = $credits->credit(
                $fixture->merchant,
                $fixture->user,
                $customerCode,
                'INV-100'.($index + 1),
                Laari::of($eligible),
                null,
                CarbonImmutable::now('UTC')->subHour(),
            );

            Carbon::setTestNow($base->addDays(3)->addMinutes($index));
            $transitions->makePayable($transaction, Actor::system());

            $fixture->transactions[] = $transaction->refresh();
        }

        Carbon::setTestNow($base->addDays(4));

        return $fixture;
    }

    /**
     * A line's due from the §4 stored integers: cashback + fee + gst.
     */
    public function due(int $index): int
    {
        $transaction = $this->transactions[$index];

        return $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;
    }

    /**
     * @return list<int>
     */
    public function transactionIds(): array
    {
        return array_map(fn (Transaction $transaction): int => $transaction->id, $this->transactions);
    }
}
