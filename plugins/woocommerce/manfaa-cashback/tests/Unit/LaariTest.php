<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Tests\Unit;

use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Money\Estimator;
use Manfaa\Cashback\Money\Laari;
use WP_UnitTestCase;

final class LaariTest extends WP_UnitTestCase
{
    public function test_rounds_to_two_places_before_scaling(): void
    {
        // 0.29 * 100 is 28.999999999999996 in binary float; never (int) it.
        self::assertSame(29, Laari::fromDecimal(0.29));
        self::assertSame(118000, Laari::fromDecimal('1180.00'));
        self::assertSame(118000, Laari::fromDecimal(1180.004999));
        self::assertSame(1, Laari::fromDecimal(0.01));
        self::assertSame(0, Laari::fromDecimal(0.004));
        self::assertSame(-250, Laari::fromDecimal(-2.5));
        // Six-decimal cart math, as WooCommerce carries it.
        self::assertSame(3333, Laari::fromDecimal(33.333333));
        self::assertSame(6667, Laari::fromDecimal(66.666667));
    }

    public function test_formats_mvr(): void
    {
        self::assertSame('27.50', Laari::toMvr(2750));
        self::assertSame('0.05', Laari::toMvr(5));
        self::assertSame('-1.00', Laari::toMvr(-100));
    }

    public function test_cashback_is_the_platform_ceiling_rule(): void
    {
        self::assertSame(2360, Laari::cashback(118000, 200));
        self::assertSame(1, Laari::cashback(1, 200));     // ceiling: 0.02 laari → 1
        self::assertSame(0, Laari::cashback(118000, 0));
        self::assertSame(0, Laari::cashback(0, 500));
    }

    /** The server's MixedBasketTest fixture: fruits excluded, veggies 2.00 %, default 5.00 %. */
    public function test_estimate_equals_the_server_fixture(): void
    {
        $card = new RateCard(500, 5000, true, [
            ['slug' => 'fruits', 'name_en' => 'Fruits', 'name_dv' => null, 'mode' => 'excluded', 'rate_bp' => 0, 'position' => 0, 'active' => true],
            ['slug' => 'veggies', 'name_en' => 'Veggies', 'name_dv' => null, 'mode' => 'rate', 'rate_bp' => 200, 'position' => 1, 'active' => true],
        ], time());

        $result = Estimator::estimate($card, ['fruits' => 30000, 'veggies' => 25000, '' => 45000]);

        self::assertSame(100000, $result['eligible_laari']);
        self::assertSame(0 + 500 + 2250, $result['estimate_laari']);
        self::assertSame(0, $result['shortfall_laari']);
    }

    public function test_rounds_per_bucket_not_per_item(): void
    {
        $card = new RateCard(250, 0, false, [], time());

        // Two items of 1 laari each at 2.50 %: per item would be 1 + 1 = 2; per bucket 2 * 250 / 10000 → ceil → 1.
        $result = Estimator::estimate($card, ['' => 2]);
        self::assertSame(1, $result['estimate_laari']);
    }

    public function test_below_minimum_shows_the_shortfall(): void
    {
        $card = new RateCard(200, 5000, false, [], time());
        $result = Estimator::estimate($card, ['' => 4999]);

        self::assertSame(0, $result['estimate_laari']);
        self::assertSame(1, $result['shortfall_laari']);
    }

    public function test_percent_strings_become_basis_points(): void
    {
        self::assertSame(250, RateCard::bp('2.50'));
        self::assertSame(500, RateCard::bp('5'));
        self::assertSame(1000, RateCard::bp('10.00'));
        self::assertSame(5, RateCard::bp('0.05'));
        self::assertSame(0, RateCard::bp('abc'));
    }
}
