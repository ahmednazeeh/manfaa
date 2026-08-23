<?php

declare(strict_types=1);

namespace App\Domain\Money;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The per-merchant money read cache (owner, 2026-08-23 — phase 2 of the
 * dashboard speed work; phase 1 was client-side).
 *
 * Every expensive money read (the home tallies, the outstanding summary,
 * the settle-all preview) is cached under a key that embeds a PER-MERCHANT
 * VERSION NUMBER. Anything that moves that merchant's money bumps the
 * version, which orphans every cached read at once — no key enumeration,
 * no race between clearing and rewriting, and a bump is one Redis INCR.
 * Orphaned entries die by TTL.
 *
 * Correctness rules this file lives by:
 *  - Only PLAIN ARRAYS are cached, never Eloquent models (a serialized
 *    model from one build unserializes as __PHP_Incomplete_Class under the
 *    next — that bug already ate a push notification once).
 *  - The version counter is read back with an (int) cast: the Redis store
 *    hands numerics back as STRINGS.
 *  - Bumps run AFTER COMMIT: a rolled-back credit must not invalidate, and
 *    a reader mid-transaction must not cache the pre-commit world under
 *    the post-commit version.
 *  - TTL is a REAPER, not the freshness mechanism. Freshness comes from
 *    the bumps; the TTL only stops orphaned versions accumulating, and
 *    bounds drift for the few figures that move with the clock rather
 *    than with events (a discount ageing across midnight).
 */
final class MerchantMoneyCache
{
    /** Reaper TTL for cached reads; freshness is the version bump's job. */
    private const int TTL = 300;

    /**
     * A missing version seeds from the clock, so a flushed Redis can never
     * resurrect entries cached under an older counter's numbering.
     */
    private function version(int $merchantId): int
    {
        $key = self::versionKey($merchantId);
        $version = Cache::get($key);

        if ($version === null) {
            // add() is atomic: two concurrent seeds agree on one value.
            Cache::add($key, time(), now()->addDays(2));
            $version = Cache::get($key);
        }

        return (int) $version;
    }

    /**
     * Something moved this merchant's money. Safe to call liberally —
     * a bump is one INCR — and always deferred to after the surrounding
     * transaction commits (immediate when there is none).
     */
    public static function bump(int $merchantId): void
    {
        DB::afterCommit(function () use ($merchantId): void {
            $key = self::versionKey($merchantId);

            if (! Cache::add($key, time(), now()->addDays(2))) {
                Cache::increment($key);
            }
        });
    }

    /**
     * @template T of array
     *
     * @param  Closure(): T  $compute  must return a plain, JSON-able array
     * @return T
     */
    public function remember(int $merchantId, string $name, Closure $compute): array
    {
        $key = sprintf('mmc:%d:%d:%s', $merchantId, $this->version($merchantId), $name);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $compute();
        Cache::put($key, $fresh, self::TTL);

        return $fresh;
    }

    private static function versionKey(int $merchantId): string
    {
        return 'mmc:v:'.$merchantId;
    }
}
