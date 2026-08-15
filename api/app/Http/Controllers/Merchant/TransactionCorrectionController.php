<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Adjustment\InvalidReversalStateException;
use App\Domain\Adjustment\ReversalOutcome;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\AmendmentService;
use App\Domain\Cashback\LinePricingException;
use App\Domain\Cashback\NotAmendableException;
use App\Domain\Money\Laari;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two corrections a merchant may make to their OWN sale while their
 * validation window is still open: fix the amount, or cancel it outright.
 *
 * Both were reachable through the vendor API and the admin panel but not
 * from the merchant's own screen, which is where a cashier notices the
 * mistake — so the panel showed a wrong sale with nothing to do about it.
 *
 * Manager and above (route middleware): keying a sale in is staff work,
 * un-keying one moves money that has already been promised to a customer.
 */
class TransactionCorrectionController extends Controller
{
    /** PATCH — correct the amounts of a sale still inside its window. */
    public function amend(Request $request, int $id, AmendmentService $amendments): JsonResponse
    {
        $validated = $request->validate([
            'eligible_amount' => ['required', 'integer', 'min:1'],
            'sale_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines' => ['sometimes', 'nullable', 'array', 'min:1'],
            'lines.*.category' => ['present', 'nullable', 'string', 'max:80'],
            'lines.*.amount_laari' => ['required', 'integer', 'min:1'],
        ]);

        $transaction = $this->ownTransaction($request, $id);
        $user = $this->user($request);

        $saleAmount = array_key_exists('sale_amount', $validated) && $validated['sale_amount'] !== null
            ? Laari::of((int) $validated['sale_amount'])
            : null;

        // A reference-only total below the eligible amount describes no
        // real receipt — the same check the credit form makes.
        if ($saleAmount !== null && $saleAmount->value() < (int) $validated['eligible_amount']) {
            return response()->json([
                'message' => 'The full sale total cannot be less than the eligible amount.',
                'code' => 'sale_below_eligible',
            ], 422);
        }

        try {
            $amended = $amendments->amend(
                $transaction,
                Actor::merchantUser($user->id),
                Laari::of((int) $validated['eligible_amount']),
                $saleAmount,
                $validated['lines'] ?? null,
            );
        } catch (NotAmendableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], 409);
        } catch (LinePricingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], 422);
        }

        return (new TransactionResource($amended->load('lines')))
            ->response($request);
    }

    /** POST — cancel the sale outright; the cashback comes straight back off. */
    public function cancel(Request $request, int $id, ReversalService $reversals): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:refund,void,duplicate,error'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->ownTransaction($request, $id);
        $user = $this->user($request);

        try {
            $outcome = $reversals->reverse(
                $transaction,
                Actor::merchantUser($user->id),
                $validated['reason'],
                CarbonImmutable::now('UTC'),
                $validated['note'] ?? null,
            );
        } catch (BackdatedIrreversibleException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'backdated_irreversible',
            ], 409);
        } catch (InvalidReversalStateException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'invalid_state',
            ], 409);
        }

        // Inside the validation window a reversal is always in place. The
        // adjustment branch exists for rows locked in a settlement or
        // already confirmed, which this endpoint refuses above — so if it
        // is ever reached, say what happened rather than return a row that
        // looks unchanged.
        if ($outcome->outcome !== ReversalOutcome::REVERSED) {
            return response()->json([
                'message' => 'This sale is already committed; the platform has recorded a credit note instead.',
                'code' => 'adjustment_created',
            ], 409);
        }

        return (new TransactionResource($outcome->transaction->refresh()->load('lines')))
            ->response($request);
    }

    /**
     * Scoped through the merchant's own relation: another merchant's id is
     * deliberately indistinguishable from one that does not exist.
     */
    private function ownTransaction(Request $request, int $id): Transaction
    {
        return $this->user($request)->merchant
            ->transactions()
            ->whereKey($id)
            ->firstOrFail();
    }

    private function user(Request $request): MerchantUser
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user;
    }
}
