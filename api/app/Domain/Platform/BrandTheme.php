<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront's admin-chosen accent colour (owner, 2026-08-17 — after
 * the teal↔coral debate, the hue becomes a superadmin setting instead of a
 * deploy).
 *
 * Stored as a plain `#rrggbb` under ONE platform_settings key. Null means
 * "unset": the customer web keeps its built-in stylesheet byte-identical
 * and no override is injected — so until an admin picks a colour, nothing
 * anywhere changes. The WEB derives its whole token set (light, dark,
 * soft wash, foreground) from this one value with the same
 * lightness-governed recipe the hand-tuned teal used, so any picked hue
 * stays AA-readable; the picker chooses the hue, the recipe guards the
 * contrast.
 */
final class BrandTheme
{
    public const string KEY = 'web.brand_color';

    private const string CACHE_KEY = 'platform_settings.web_brand_color';

    private const int CACHE_TTL_SECONDS = 60;

    public const string HEX_PATTERN = '/^#[0-9a-f]{6}$/i';

    public static function current(): ?string
    {
        /** @var ?string $value */
        $value = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function (): ?string {
                if (! Schema::hasTable('platform_settings')) {
                    return null; // deploy-order guard, like PlatformConfig
                }

                $stored = PlatformSetting::query()->where('key', self::KEY)->first()?->value;

                return is_string($stored) && preg_match(self::HEX_PATTERN, $stored) === 1
                    ? $stored
                    : null;
            },
        );

        return $value;
    }

    /** Null clears the override — the storefront returns to its built-in hue. */
    public static function set(?string $hex): void
    {
        if ($hex === null) {
            PlatformSetting::query()->where('key', self::KEY)->delete();
        } else {
            PlatformSetting::query()->updateOrCreate(
                ['key' => self::KEY],
                ['value' => strtolower($hex)],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }
}
