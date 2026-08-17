<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile release gates — minimum build, latest build and store URL per
 * app and platform — editable from the admin panel.
 *
 * Storage follows PlatformConfig exactly: one platform_settings row per
 * value, keyed `mobile.{app}.{platform}.{field}`, and an absent row answers
 * the env-backed default from config/mobile.php — so behaviour is
 * byte-identical until an admin actually saves. Reads are cached for 60
 * seconds; a save busts the cache immediately, because raising
 * `minimum_build` is an emergency lever and a stale answer is a bad build
 * still talking to the API.
 *
 * The apps come from config('mobile') itself: declare a new app there and
 * this class (and the admin endpoints over it) pick it up without editing.
 */
final class AppReleaseConfig
{
    private const string CACHE_KEY = 'platform_settings.mobile_releases';

    private const int CACHE_TTL_SECONDS = 60;

    /** Order matters: it mirrors config/mobile.php, keeping the public /config bytes stable. */
    public const array PLATFORMS = ['ios', 'android'];

    /** @return list<string> the apps config/mobile.php declares (customer, merchant, …) */
    public static function apps(): array
    {
        return array_keys((array) config('mobile'));
    }

    /**
     * Every app and platform with its EFFECTIVE flags: the stored override
     * when one exists, the env default otherwise. `store_url` is null when
     * nothing is set — the /config endpoint turns that into the empty
     * string the apps already expect.
     *
     * @return array<string, array<string, array{minimum_build: int, latest_build: int, store_url: ?string}>>
     */
    public function all(): array
    {
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => $this->resolve());

        // An older build (or anything else) in the slot is a miss, not a
        // crash — the same defensive posture as NotificationService.
        return is_array($cached) ? $cached : $this->resolve();
    }

    /**
     * Writes the FULL flag set as overrides and busts the cache, so the new
     * gate is live on the next /config read.
     *
     * @param  array<string, array<string, array{minimum_build: int, latest_build: int, store_url: ?string}>>  $flags
     */
    public function put(array $flags, ?int $updatedBy = null): void
    {
        foreach (self::apps() as $app) {
            foreach (self::PLATFORMS as $platform) {
                foreach (['minimum_build', 'latest_build', 'store_url'] as $field) {
                    if (! array_key_exists($field, $flags[$app][$platform] ?? [])) {
                        continue;
                    }

                    // The jsonb value column is NOT NULL, so a CLEARED
                    // store_url is stored as '' — resolve() normalises it
                    // back to null, and the row's existence is what keeps
                    // the env default from resurfacing.
                    PlatformSetting::query()->updateOrCreate(
                        ['key' => self::key($app, $platform, $field)],
                        ['value' => $flags[$app][$platform][$field] ?? '', 'updated_by' => $updatedBy],
                    );
                }
            }
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, array{minimum_build: int, latest_build: int, store_url: ?string}>>
     */
    private function resolve(): array
    {
        $overrides = [];

        // Deploy-order safety, exactly as PlatformConfig::get(): until the
        // table exists every value answers its env default.
        if (Schema::hasTable('platform_settings')) {
            $overrides = PlatformSetting::query()
                ->where('key', 'like', 'mobile.%')
                ->pluck('value', 'key')
                ->all();
        }

        $out = [];

        foreach (self::apps() as $app) {
            foreach (self::PLATFORMS as $platform) {
                $out[$app][$platform] = [
                    // (int) casts on BOTH paths: jsonb round-trips ints, but
                    // Redis passes cached numerics back as STRINGS (the
                    // PlatformConfig::get gotcha) — never trust the type.
                    'minimum_build' => (int) $this->effective($overrides, $app, $platform, 'minimum_build'),
                    'latest_build' => (int) $this->effective($overrides, $app, $platform, 'latest_build'),
                    'store_url' => self::url($this->effective($overrides, $app, $platform, 'store_url')),
                ];
            }
        }

        return $out;
    }

    /**
     * Override-if-set else env. array_key_exists, not `??`: a store_url
     * deliberately CLEARED by an admin is a null row, and null-coalescing
     * would resurrect the env value it was cleared from.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function effective(array $overrides, string $app, string $platform, string $field): mixed
    {
        $key = self::key($app, $platform, $field);

        return array_key_exists($key, $overrides)
            ? $overrides[$key]
            : config(sprintf('mobile.%s.%s.%s', $app, $platform, $field));
    }

    private static function key(string $app, string $platform, string $field): string
    {
        return sprintf('mobile.%s.%s.%s', $app, $platform, $field);
    }

    private static function url(mixed $value): ?string
    {
        $url = trim((string) $value);

        return $url === '' ? null : $url;
    }
}
