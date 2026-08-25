<?php

declare(strict_types=1);

namespace Tests\Feature\FeePromotions;

use App\Domain\Cashback\ManualCreditService;
use App\Domain\Money\Laari;
use App\Domain\Platform\FeePromotionPolicy;
use App\Models\Customer;
use App\Models\FeePromotion;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Platform fee promotions, switched on from a test the way a superadmin
 * switches them on — the single row plus the cache bust, never a mocked
 * policy. A test that mocked the settings would prove the arithmetic and
 * nothing about the switch.
 *
 * The default merchant here trades at 2.00% cashback, which §4 prices at a
 * 0.75% platform fee. That is the "before" price every assertion below
 * compares against.
 *
 * Not a *Test.php file — PHPUnit never collects it.
 */
final class FeePromotionFixture
{
    /** The standing cashback rate every fixture merchant trades at. */
    public const int RATE_BP = 200;

    /** The §4 tier fee for RATE_BP — what a sale costs with no promotion. */
    public const int TIER_FEE_BP = 75;

    public static function intro(int $days, int $feeBp): FeePromotion
    {
        return self::write([
            'intro_enabled' => true,
            'intro_days' => $days,
            'intro_fee_bp' => $feeBp,
            'intro_banner_en' => 'No platform fee for your first '.$days.' days.',
            'intro_banner_dv' => 'ފުރަތަމަ '.$days.' ދުވަހު ޕްލެޓްފޯމް ފީއެއް ނުނަގާނެ.',
        ]);
    }

    public static function platformWide(CarbonImmutable $from, CarbonImmutable $to, int $feeBp): FeePromotion
    {
        return self::write([
            'wide_enabled' => true,
            'wide_from' => $from->utc(),
            'wide_to' => $to->utc(),
            'wide_fee_bp' => $feeBp,
            'wide_banner_en' => 'Launch offer: reduced platform fee for every store.',
            'wide_banner_dv' => 'ލޯންޗް އޮފަރ: ހުރިހާ ފިހާރައަކަށް ޕްލެޓްފޯމް ފީ ކުޑަކޮށްފި.',
        ]);
    }

    /** Ending a promotion — the act every frozen-terms assertion turns on. */
    public static function endAll(): FeePromotion
    {
        return self::write(['intro_enabled' => false, 'wide_enabled' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function write(array $attributes): FeePromotion
    {
        $settings = FeePromotion::current();
        $settings->forceFill($attributes)->save();

        // The same bust the settings endpoint performs: the next sale must
        // price under the new terms, not up to a cache TTL later.
        FeePromotionPolicy::forget();

        return $settings->refresh();
    }

    /**
     * A trading store with an approval stamp — the instant its introductory
     * window starts counting from.
     */
    public static function merchant(?CarbonImmutable $approvedAt, int $rateBp = self::RATE_BP): Merchant
    {
        $merchant = Merchant::factory()->create([
            'name' => 'Promo Shop '.Str::upper(Str::random(4)),
            'validation_window_days' => 3,
            'min_eligible_laari' => 5000,
            'approved_at' => $approvedAt?->utc(),
        ]);

        MerchantRate::factory()->for($merchant)->create([
            'rate_bp' => $rateBp,
            'effective_from' => CarbonImmutable::parse('2020-01-01T00:00:00Z'),
            'effective_to' => null,
        ]);

        return $merchant;
    }

    public static function owner(Merchant $merchant): MerchantUser
    {
        return MerchantUser::factory()->for($merchant)->owner()->create();
    }

    public static function customer(): Customer
    {
        return Customer::factory()->create([
            'name' => 'Aishath Nizama',
            'customer_code' => (string) fake()->unique()->numberBetween(100000, 899999),
            'payout_bank' => 'bml',
            'payout_account' => (string) fake()->unique()->numberBetween(7730000000000, 7739999999999),
            'payout_account_name' => 'Aishath Nizama',
        ]);
    }

    /** One sale, through the real credit path. */
    public static function credit(
        Merchant $merchant,
        MerchantUser $owner,
        Customer $customer,
        int $eligibleLaari,
        CarbonImmutable $occurredAt,
        ?array $lines = null,
    ): Transaction {
        return app(ManualCreditService::class)->credit(
            $merchant,
            $owner,
            $customer->customer_code,
            'INV-'.Str::upper(Str::random(8)),
            Laari::of($eligibleLaari),
            null,
            $occurredAt,
            $lines,
        )->refresh();
    }
}
