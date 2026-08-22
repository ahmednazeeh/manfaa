<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\CustomerPayout;
use App\Models\TransferProfile;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sending one queued payout, and writing down what the bank said.
 *
 * The rules that keep this from paying twice, in the order they matter:
 *
 * 1. **Nothing is ever sent from `pending_approval` or `sent`.** A parked
 *    transfer is alive and waiting on a second human; a sent one is done.
 *    The state check is a lock, not a hint.
 * 2. **The same `internal_ref` is reused on every attempt for a payout.**
 *    That is what lets the upstream recognise a repeat and answer 409
 *    instead of moving the money again.
 * 3. **A 409 whose existing row succeeded is a SUCCESS.** We adopt its
 *    trx_id. Treating it as a failure and retrying is the textbook double
 *    payment.
 * 4. **An unproven failure is never retried automatically.** Only error
 *    codes that prove no debit occurred return to `pending`; everything
 *    else waits for a person.
 */
final readonly class PayoutSender
{
    public function __construct(
        private TransferClient $client,
        private WalletService $wallet,
    ) {}

    /**
     * Attempt one payout.
     *
     * @param  AdminUser|null  $actor  null when a scheduled worker sends it
     */
    public function send(CustomerPayout $payout, ?TransferProfile $profile = null, ?AdminUser $actor = null): CustomerPayout
    {
        $profile ??= $this->defaultProfile();

        if ($profile === null) {
            return $this->fail($payout, 'no_profile', 'No transfer profile is configured.', $actor);
        }

        // Claim it under a lock. Two admins pressing Send at the same instant
        // must not both reach the bank with the same reference.
        $claimed = DB::transaction(function () use ($payout): ?CustomerPayout {
            $locked = CustomerPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->state, CustomerPayout::SENDABLE, true)) {
                return null;
            }

            $locked->forceFill([
                'state' => 'processing',
                'attempts' => $locked->attempts + 1,
            ])->save();

            return $locked;
        });

        if ($claimed === null) {
            // Already sent, already parked, or already in flight. Refusing
            // here is the single most important thing this class does.
            return $payout->refresh();
        }

        $result = $this->client->send(
            $profile,
            account: (string) $claimed->account,
            amountLaari: (int) $claimed->amount_laari,
            // The SAME reference every attempt — that is what makes the
            // upstream's duplicate detection work for us rather than
            // against us.
            internalRef: (string) $claimed->internal_ref,
            beneficiaryName: $claimed->account_name,
            remarks: 'Manfaa wallet withdrawal',
        );

        return match ($result->outcome) {
            TransferOutcome::Sent => $this->markSent($claimed, $result, $actor),
            TransferOutcome::PendingApproval => $this->markParked($claimed, $result, $actor),
            TransferOutcome::FailedRetryable => $this->fail(
                $claimed, $result->errorCode ?? 'failed', $result->message ?? 'Refused.', $actor, retryable: true,
            ),
            TransferOutcome::FailedNeedsReview => $this->fail(
                $claimed, $result->errorCode ?? 'failed', $result->message ?? 'Refused.', $actor, retryable: false,
            ),
        };
    }

    private function markSent(CustomerPayout $payout, TransferResult $result, ?AdminUser $actor): CustomerPayout
    {
        $payout->forceFill([
            'state' => 'sent',
            'trx_id' => $result->trxId,
            'error_code' => null,
            'failure_reason' => null,
            'processed_at' => CarbonImmutable::now(),
            'processed_by' => $actor?->getKey(),
        ])->save();

        return $payout->refresh();
    }

    private function markParked(CustomerPayout $payout, TransferResult $result, ?AdminUser $actor): CustomerPayout
    {
        $payout->forceFill([
            'state' => 'pending_approval',
            // Deliberately NOT trx_id. An approvals-queue record id is not a
            // transaction reference, and filing it as one would report an
            // unmade payment as made.
            'approval_id' => $result->approvalId,
            'failure_reason' => $result->message,
            'processed_at' => CarbonImmutable::now(),
            'processed_by' => $actor?->getKey(),
        ])->save();

        return $payout->refresh();
    }

    /**
     * A failure. The money goes back to the wallet ONLY when we can prove it
     * never left the bank — an unproven failure leaves the balance debited
     * and a person to look, because crediting a wallet for money that did
     * move is a second payment by another name.
     */
    private function fail(
        CustomerPayout $payout,
        string $code,
        string $message,
        ?AdminUser $actor,
        bool $retryable = false,
    ): CustomerPayout {
        $payout->forceFill([
            // `refunded`, NOT `failed`, when the money goes back. `failed`
            // is in SENDABLE, so an admin retrying the obvious way would
            // send a transfer whose amount is already sitting in the
            // customer's wallet — the same laari out twice. Refunded is
            // terminal: getting it out again means a fresh withdrawal,
            // which debits the wallet exactly once.
            'state' => $retryable ? 'refunded' : 'failed',
            'error_code' => $code,
            'failure_reason' => $message,
            'processed_at' => CarbonImmutable::now(),
            'processed_by' => $actor?->getKey(),
        ])->save();

        if ($retryable) {
            $this->wallet->reverse($payout, 'Transfer refused: '.$message);
        }

        return $payout->refresh();
    }

    private function defaultProfile(): ?TransferProfile
    {
        $settings = TransferSetting::current();

        return $settings->profile_id !== null
            ? TransferProfile::query()->where('active', true)->find($settings->profile_id)
            : TransferProfile::query()->where('active', true)->where('is_default', true)->first();
    }
}
