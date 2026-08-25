<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use App\Domain\Tax\TaxPolicy;
use App\Models\TaxSetting;
use Carbon\CarbonImmutable;

/**
 * The GST switch, thrown from a test the way a superadmin throws it — the
 * single row plus the cache bust, never a mocked policy. A test that mocked
 * the setting would prove the arithmetic and nothing about the switch.
 *
 * The Maldives general rate (8.00% = 800bp) is the default here because it
 * is the figure the owner's own hand-derivations use.
 *
 * Not a *Test.php file — PHPUnit never collects it.
 */
final class GstFixture
{
    /** The Maldives GST general rate, in basis points. */
    public const int RATE_BP = 800;

    public static function enable(int $rateBp = self::RATE_BP, string $treatment = 'on_top'): TaxSetting
    {
        return self::write([
            'gst_enabled' => true,
            'gst_rate_bp' => $rateBp,
            'fee_treatment' => $treatment,
            'gst_tin' => '1234567GST501',
            'gst_business_name' => 'Manfaa Pvt Ltd',
            'gst_activity_number' => 'A-0091',
            'enabled_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** A RATE EDIT, which must never re-price a single existing row. */
    public static function rate(int $rateBp): TaxSetting
    {
        return self::write(['gst_rate_bp' => $rateBp]);
    }

    /** A TREATMENT SWITCH — likewise forward-only. */
    public static function treatment(string $treatment): TaxSetting
    {
        return self::write(['fee_treatment' => $treatment]);
    }

    public static function disable(): TaxSetting
    {
        return self::write(['gst_enabled' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function write(array $attributes): TaxSetting
    {
        $settings = TaxSetting::current();
        $settings->forceFill($attributes)->save();

        // The same bust the settings endpoint performs: the next credit
        // must price under the new terms, not up to a cache TTL later.
        TaxPolicy::forget();

        return $settings->refresh();
    }
}
