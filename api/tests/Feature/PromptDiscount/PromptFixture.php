<?php

declare(strict_types=1);

namespace Tests\Feature\PromptDiscount;

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
 * Payable transactions on a merchant at the §4 200bp / 75bp tier, with the
 * PLAN §1 prompt-payment discount deliberately LEFT AT THE PLATFORM'S SHIPPED
 * DEFAULTS (500bp, 10 days) unless a test says otherwise — these tests are
 * the ones that must prove the live configuration.
 *
 * Every credit is walked through the real path (ManualCreditService → the
 * state machine), so the accrual journals and the events exist exactly as
 * they do in production. The clock is left one day after the last line became
 * payable, so nothing is old and nothing is overdue; tests that need age move
 * Carbon forward themselves.
 *
 * Not a *Test.php file — PHPUnit never collects it.
 */
final class PromptFixture
{
    public const string BASE = '2026-08-01T10:00:00+00:00';

    /** Day 0 of the settlement clock for every line this fixture creates. */
    public const string CLOCK_START = '2026-08-04T10:00:00+00:00';

    public Merchant $merchant;

    public MerchantUser $user;

    public Customer $customer;

    /** @var list<Transaction> in due-date (= allocation) order */
    public array $transactions = [];

    /**
     * @param  list<int>  $eligibles  eligible laari per sale, in the order they occur
     */
    public static function payable(array $eligibles, ?int $rateBp = null, ?int $maxAgeDays = null): self
    {
        $base = CarbonImmutable::parse(self::BASE);
        $config = app(PlatformConfig::class);

        if ($rateBp !== null) {
            $config->set('prompt_discount_rate_bp', $rateBp);
        }

        if ($maxAgeDays !== null) {
            $config->set('prompt_discount_max_age_days', $maxAgeDays);
        }

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
        $fixture->customer = Customer::factory()->create(['customer_code' => '482917']);

        $credits = app(ManualCreditService::class);
        $transitions = app(TransitionService::class);

        foreach ($eligibles as $index => $eligible) {
            Carbon::setTestNow($base->addMinutes($index));

            $transaction = $credits->credit(
                $fixture->merchant,
                $fixture->user,
                '482917',
                'INV-'.(1001 + $index),
                Laari::of($eligible),
                null,
                CarbonImmutable::now('UTC')->subHour(),
            );

            // Day 0 for every line, minutes apart so due dates — and with
            // them the oldest-first allocation order — stay deterministic.
            Carbon::setTestNow($base->addDays(3)->addMinutes($index));
            $transitions->makePayable($transaction, Actor::system());

            $fixture->transactions[] = $transaction->refresh();
        }

        Carbon::setTestNow($base->addDays(4));

        return $fixture;
    }

    /**
     * A single 100,000-laari sale: cashback 2,000 + fee 750 = 2,750 due —
     * the §4 line the discount arithmetic is hand-derived against.
     */
    public static function singleLine(): self
    {
        return self::payable([100_000]);
    }

    /** The whole §4 batch: 8,600 cashback + 3,225 fee = 11,825 due. */
    public static function fourLines(?int $rateBp = null, ?int $maxAgeDays = null): self
    {
        return self::payable([100_000, 50_000, 200_000, 80_000], $rateBp, $maxAgeDays);
    }

    /**
     * One MORE payable sale on the same merchant, credited (and started on
     * the clock) at an instant of the caller's choosing — how a test builds a
     * merchant whose outstanding lines are not all the same age. The clock is
     * restored afterwards, so "now" is wherever the caller left it.
     */
    public function addPayable(int $eligible, CarbonImmutable $creditedAt): Transaction
    {
        $now = CarbonImmutable::now('UTC');

        Carbon::setTestNow($creditedAt);

        $transaction = app(ManualCreditService::class)->credit(
            $this->merchant,
            $this->user,
            $this->customer->customer_code,
            'INV-EXTRA-'.(count($this->transactions) + 1),
            Laari::of($eligible),
            null,
            $creditedAt->subHour(),
        );

        app(TransitionService::class)->makePayable($transaction, Actor::system());

        Carbon::setTestNow($now);

        $transaction = $transaction->refresh();
        $this->transactions[] = $transaction;

        return $transaction;
    }

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
