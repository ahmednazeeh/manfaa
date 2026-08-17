<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OtpCode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retires spent OTP codes once no live verification could still need them.
 *
 * WHY THIS EXISTS: OtpService::request() inserts a row per code and
 * supersedes by UPDATE — rows are NEVER deleted, kept as an evidence trail.
 * That was fine while OTP was signup-only and low-volume. The customer app's
 * passwordless sign-in (R1) makes OTP the primary auth path: every sign-in
 * on every device, plus every resend, now adds a permanent row carrying a
 * phone number, a bcrypt code hash and a signup-token hash — and the
 * signup_token_hash unique index grows with it. This is the same unbounded
 * primary-auth write table the M5 review flagged for idempotency_keys, and
 * it gets the same treatment: a retention command on a schedule.
 *
 * The window is generous — a code lives 10 minutes and a signup token 15,
 * so anything older than a day is long dead. Kept in DAYS, not minutes, so
 * a support question about "the code I got this morning" still has a row.
 */
class PruneOtpCodesCommand extends Command
{
    protected $signature = 'manfaa:prune-otp-codes {--days=7 : Retain codes for this many days}';

    protected $description = 'Delete OTP codes whose verification window is long past';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        // Chunked: one unbounded DELETE would hold a lock across every
        // sign-in trying to write a fresh code.
        $deleted = 0;

        do {
            $batch = OtpCode::query()
                ->where('created_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += OtpCode::query()->whereIn('id', $batch)->delete();
        } while (true);

        $this->info("Pruned {$deleted} OTP codes older than {$days} days.");

        return self::SUCCESS;
    }
}
