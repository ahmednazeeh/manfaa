<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Typed access to the platform_settings key-value table. Every key has a
 * hardcoded default equal to the constant it replaced, so behaviour is
 * byte-identical until an admin writes a value. Reads are cached for 60
 * seconds; writes bust the key's cache entry immediately.
 */
final class PlatformConfig
{
    private const string CACHE_PREFIX = 'platform_settings.';

    private const int CACHE_TTL_SECONDS = 60;

    /**
     * Key => [default, allowed integer range]. The defaults ARE the previous
     * hardcoded constants (§7 clock, §13 minimum payout, merchant defaults).
     *
     * @var array<string, array{default: int, min: int, max: int}>
     */
    public const array KEYS = [
        'min_payout_laari' => ['default' => 10000, 'min' => 0, 'max' => 1000000],
        'settlement_due_days' => ['default' => 15, 'min' => 1, 'max' => 60],
        'write_off_days' => ['default' => 90, 'min' => 30, 'max' => 365],
        'default_validation_window_days' => ['default' => 3, 'min' => 0, 'max' => 30],
        'default_min_eligible_laari' => ['default' => 5000, 'min' => 0, 'max' => 1000000],
        // Prompt-payment discount (PLAN §1). 0 turns the incentive OFF
        // entirely — every batch then prices exactly as it did before the
        // feature existed. The 2000bp ceiling mirrors the §4 rate cap.
        'prompt_discount_rate_bp' => ['default' => 500, 'min' => 0, 'max' => 2000],
        // Must stay SHORTER than settlement_due_days (15): a window at or
        // past the due date rewards nothing, because every line still owed
        // on the due date would qualify. Capped at 15 so it can never be
        // set beyond the clock itself.
        'prompt_discount_max_age_days' => ['default' => 10, 'min' => 1, 'max' => 15],
    ];

    public function minPayoutLaari(): int
    {
        return $this->get('min_payout_laari');
    }

    public function settlementDueDays(): int
    {
        return $this->get('settlement_due_days');
    }

    public function writeOffDays(): int
    {
        return $this->get('write_off_days');
    }

    public function defaultValidationWindowDays(): int
    {
        return $this->get('default_validation_window_days');
    }

    public function defaultMinEligibleLaari(): int
    {
        return $this->get('default_min_eligible_laari');
    }

    /**
     * PLAN §1: basis points off the PLATFORM FEE (never the customer's
     * cashback) when a merchant settles everything outstanding promptly.
     * Zero disables the incentive.
     */
    public function promptDiscountRateBp(): int
    {
        return $this->get('prompt_discount_rate_bp');
    }

    /**
     * How young every line in the batch must be, in whole days since
     * clock_start_at, for the discount to be granted.
     */
    public function promptDiscountMaxAgeDays(): int
    {
        return $this->get('prompt_discount_max_age_days');
    }

    public function get(string $key): int
    {
        $spec = self::KEYS[$key] ?? throw InvalidSettingException::unknownKey($key);

        // (int) cast: Laravel's Redis cache store passes numeric values
        // through UNserialized, so a cached int comes back as a numeric
        // string in production while the array store used in tests returns
        // a real int. Without the cast this method TypeErrors on every
        // cache HIT under Redis.
        return (int) Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL_SECONDS,
            function () use ($key, $spec): int {
                // Deploy-order safety: code can reach production before the
                // platform_settings migration runs; until the table exists
                // every key answers its hardcoded default. hasTable, not a
                // QueryException catch — a failed query would abort any
                // surrounding DB transaction.
                if (! Schema::hasTable('platform_settings')) {
                    return $spec['default'];
                }

                $value = PlatformSetting::query()->where('key', $key)->first()?->value;

                return is_int($value) ? $value : $spec['default'];
            },
        );
    }

    /**
     * Validates against the key's integer range, upserts, and busts the
     * cache so the new value is live immediately.
     */
    public function set(string $key, int $value, ?int $updatedBy = null): PlatformSetting
    {
        $spec = self::KEYS[$key] ?? throw InvalidSettingException::unknownKey($key);

        if ($value < $spec['min'] || $value > $spec['max']) {
            throw InvalidSettingException::outOfRange($key, $value, $spec['min'], $spec['max']);
        }

        $setting = PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy],
        );

        Cache::forget(self::CACHE_PREFIX.$key);

        return $setting;
    }

    /**
     * Every key with its effective value, default and allowed range — the
     * admin GET payload.
     *
     * @return array<string, array{value: int, default: int, min: int, max: int, overridden: bool}>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::KEYS as $key => $spec) {
            $value = $this->get($key);

            $out[$key] = [
                'value' => $value,
                'default' => $spec['default'],
                'min' => $spec['min'],
                'max' => $spec['max'],
                'overridden' => $value !== $spec['default'],
            ];
        }

        return $out;
    }
}
