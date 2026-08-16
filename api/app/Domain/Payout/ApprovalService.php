<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\AdminUser;
use App\Models\PayoutBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One admin approves, and the batch may be exported: draft → approved,
 * stamped with who did it and when. The platform has a single admin account,
 * so the two-distinct-approvers gate this replaced could never be passed at
 * all. Approval stays a real state transition in the domain — the UI hiding
 * a button is not a control.
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

            $batch->forceFill([
                'approved_by' => $approver->id,
                'approved_at' => CarbonImmutable::now('UTC'),
                'state' => PayoutBatchState::Approved,
            ])->save();

            return $batch;
        });
    }
}
