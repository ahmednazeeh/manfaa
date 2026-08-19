<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\PayoutSender;
use App\Models\AdminUser;
use App\Models\CustomerPayout;
use App\Models\TransferProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One customer payout, off the web request.
 *
 * A transfer can take two minutes (owner 2026-08-19) and nginx hangs up at
 * sixty seconds, so doing this inside the request handed the admin a 504
 * while the money was still moving — an error page for a transfer that very
 * probably succeeded. The row is claimed before the call either way, so the
 * outcome was always recorded correctly; what was broken was the operator
 * being told.
 */
class SendOnePayoutViaApi implements ShouldQueue
{
    use Queueable;

    /** No blind retry: see the batch jobs. */
    public int $tries = 1;

    /** Comfortably past the client's own 180s ceiling. */
    public int $timeout = 300;

    public function __construct(
        private readonly int $payoutId,
        private readonly ?int $profileId = null,
        private readonly ?int $actorId = null,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('customer-payout:'.$this->payoutId))
                ->dontRelease()
                ->expireAfter(400),
        ];
    }

    public function handle(PayoutSender $sender): void
    {
        $payout = CustomerPayout::query()->find($this->payoutId);

        if ($payout === null) {
            return;
        }

        $sender->send(
            $payout,
            $this->profileId !== null ? TransferProfile::query()->find($this->profileId) : null,
            $this->actorId !== null ? AdminUser::query()->find($this->actorId) : null,
        );
    }
}
