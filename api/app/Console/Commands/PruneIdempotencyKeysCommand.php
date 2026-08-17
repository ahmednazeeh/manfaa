<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retires idempotency keys once no honest client could still be retrying.
 *
 * WHY THIS EXISTS: a key row is deleted only when the request FAILED (so an
 * honest retry can run again) or when an abandoned claim is taken over. A
 * SUCCESSFUL write's row was kept forever, each carrying a full copy of its
 * response body. That was tolerable while the only writers were a bounded
 * set of POS vendor integrations; M5 pointed every till sale from every
 * merchant app at the same table.
 *
 * Unbounded retention is not only growth. The replay window never closing
 * means a device whose local key counter restarts — a factory reset, a
 * restore from backup — presents keys used months earlier against the same
 * (merchant_id, key) unique index, and every one of those sales is refused
 * as `idempotency_key_reuse_mismatch`, which the published contract calls a
 * terminal client bug. There is no operator command to release them.
 *
 * The window is deliberately generous. A till draining a queue after days
 * without signal must still find its keys, so this is measured in weeks,
 * not hours — long past any retry, well short of forever.
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'manfaa:prune-idempotency-keys {--days=30 : Retain keys for this many days}';

    protected $description = 'Delete idempotency keys older than the retry window';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        // Chunked: one unbounded DELETE on a table this size would hold a
        // lock across every till trying to record a sale.
        $deleted = 0;

        do {
            $batch = IdempotencyKey::query()
                ->where('created_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += IdempotencyKey::query()->whereIn('id', $batch)->delete();
        } while (true);

        $this->info("Pruned {$deleted} idempotency keys older than {$days} days.");

        return self::SUCCESS;
    }
}
