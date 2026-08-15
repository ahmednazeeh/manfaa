<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Adjustment\InvalidReversalStateException;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AdjustmentResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\AdminUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin adjustment — the platform's correction of last resort, and the
 * escape hatch the published contract has always named: docs/openapi.yaml
 * says "a correction is an admin adjustment" and "Corrections are admin
 * adjustments — contact the platform", and PLAN §1 pins the backdated-credit
 * decision to "(admin adjustment only)".
 *
 * It is the SAME §9.2 tree the vendor path runs (ReversalService), with one
 * difference: an admin actor is not stopped by the backdated flag. That flag
 * exists to stop the merchant and their vendor from taking back a credit they
 * were warned was final — not to make the platform powerless when a POS
 * backfills a week of already-refunded sales. What actually happens is the
 * decision tree's, not this controller's:
 *
 *   - pre-confirmation and not frozen in a live batch → reversed in place,
 *     the accrual mirrored from the STORED integers;
 *   - confirmed, paid, or frozen in a non-draft settlement → a pending
 *     credit adjustment that nets the merchant's next batch (§7).
 *
 * Everything lands through TransitionService and Postings, so the event log
 * records the admin actor and the reason, the journals stay balanced, and
 * the reconciler stays green — which is exactly what hand-written SQL on the
 * production database, the only alternative before this existed, does not do.
 *
 * A note is REQUIRED here (it is optional on the vendor path): a correction
 * the platform makes to a merchant's money must say why, in words, on the
 * transaction's own history.
 */
final class AdjustmentController extends Controller
{
    public function store(Request $request, int $id, ReversalService $reversals): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:customer_refund,till_void,duplicate,other'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $transaction = Transaction::query()->findOrFail($id);

        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        try {
            $outcome = $reversals->reverse(
                $transaction,
                Actor::admin($admin->id),
                $validated['reason'],
                CarbonImmutable::now('UTC'),
                $validated['note'],
            );
        } catch (InvalidReversalStateException $e) {
            // reversed / written_off are terminal for everyone, admins
            // included: there is nothing left to correct.
            abort(409, $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'outcome' => $outcome->outcome,
                'cause' => $outcome->cause,
                'adjustment' => $outcome->adjustment !== null
                    ? (new AdjustmentResource($outcome->adjustment))->resolve($request)
                    : null,
                'transaction' => (new TransactionResource($outcome->transaction))->resolve($request),
            ],
        ], 200);
    }
}
