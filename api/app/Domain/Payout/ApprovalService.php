<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\AdminUser;
use App\Models\PayoutBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dual approval (§6): the first approve() records the first approver and
 * leaves the batch draft; the second approve() must come from a different
 * admin and moves it to approved. Enforced here, in the domain — the UI
 * hiding a button is not a control.
 */
final readonly class ApprovalService
{
    public function approve(PayoutBatch $batch, AdminUser $approver): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $approver): PayoutBatch {
            PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->refresh();

            if ($batch->state !== PayoutBatchState::Draft) {
                throw InvalidPayoutBatchStateException::approve($batch);
            }

            $now = CarbonImmutable::now('UTC');

            if ($batch->approved_by_first === null) {
                $batch->forceFill([
                    'approved_by_first' => $approver->id,
                    'first_approved_at' => $now,
                ])->save();

                return $batch;
            }

            if ($batch->approved_by_first === $approver->id) {
                throw SameApproverException::for($batch, $approver);
            }

            $batch->forceFill([
                'approved_by_second' => $approver->id,
                'second_approved_at' => $now,
                'state' => PayoutBatchState::Approved,
            ])->save();

            return $batch;
        });
    }
}
