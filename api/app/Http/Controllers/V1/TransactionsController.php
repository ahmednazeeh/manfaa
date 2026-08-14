<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Adjustment\InvalidReversalStateException;
use App\Domain\Adjustment\ReversalOutcome;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ApiCreditService;
use App\Domain\Cashback\CustomerNotFoundException;
use App\Domain\Cashback\CustomerRef;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\FutureDatedTransactionException;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Money\Laari;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Resources\V1\AdjustmentResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * §9.2 vendor endpoints: record a sale, reverse a sale. This controller
 * never rejects a well-formed sale for business reasons — below-minimum
 * and suspended-merchant sales are recorded with zero cashback and a
 * distinct 200 body, so the cashier always sees something truthful.
 */
class TransactionsController extends V1Controller
{
    // ISO 8601 with an explicit UTC offset (§9.2). An offset-less
    // wall-clock string would be read as UTC and freeze the rate at the
    // wrong instant, so it is rejected outright.
    private const string OCCURRED_AT_FORMATS = 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:sO';

    public function store(Request $request, ApiCreditService $credits): JsonResponse
    {
        $data = $this->validateEnvelope($request, [
            'invoice_no' => ['required', 'string', 'max:64'],
            // Dual ref (CustomerRef): 6-digit code, or a Maldivian mobile
            // (+960XXXXXXX / 7-digit local starting 7 or 9). Phone-keyed
            // sales record origin api_phone; code-keyed stay pos.
            'customer_ref' => ['required', 'string', 'regex:'.CustomerRef::PATTERN],
            'eligible_amount' => ['required', 'integer', 'min:1'],
            'sale_amount' => ['nullable', 'integer', 'min:1'],
            'occurred_at' => ['required', self::OCCURRED_AT_FORMATS],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $merchant = $this->merchant($request);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($branchId !== null && ! MerchantBranch::query()->whereKey($branchId)->where('merchant_id', $merchant->id)->exists()) {
            return $this->error(422, 'validation_failed', 'The given data was invalid.', errors: [
                'branch_id' => ['The branch_id does not belong to this merchant.'],
            ]);
        }

        try {
            $transaction = $credits->credit(
                merchant: $merchant,
                actor: $this->actor($merchant),
                customerRef: $data['customer_ref'],
                invoiceNo: $data['invoice_no'],
                eligible: Laari::of((int) $data['eligible_amount']),
                saleAmount: isset($data['sale_amount']) ? Laari::of((int) $data['sale_amount']) : null,
                occurredAt: CarbonImmutable::parse($data['occurred_at']),
                branchId: $branchId,
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (CustomerNotFoundException $exception) {
            return $this->error(422, 'customer_not_found', $exception->getMessage());
        } catch (FutureDatedTransactionException) {
            return $this->error(422, 'future_dated', 'occurred_at is in the future beyond the permitted clock-skew allowance.');
        } catch (NoEffectiveRateException) {
            return $this->error(422, 'no_effective_rate', 'No cashback rate is effective at occurred_at — contact the platform.');
        } catch (MerchantNotActiveException) {
            // Closed or never-approved merchant (draft / pending_review /
            // rejected) — the credential should not exist or should already
            // be revoked; there is no registry code for this, so refuse like
            // a missing grant rather than record a sale for a merchant that
            // is not trading on the platform.
            return $this->error(403, 'forbidden_ability', 'This merchant account is not active on the platform.');
        } catch (DuplicateInvoiceException $exception) {
            $existing = Transaction::query()
                ->where('merchant_id', $merchant->id)
                ->where('invoice_no', $data['invoice_no'])
                ->first();

            return $this->error(
                409,
                'duplicate_invoice',
                $exception->getMessage(),
                meta: ['transaction_id' => $existing?->id],
            );
        }

        [$status, $httpStatus] = match ($transaction->reason_code) {
            'merchant_suspended' => ['recorded_ineligible', 200],
            'below_minimum' => ['below_minimum', 200],
            default => ['created', 201],
        };

        return new JsonResponse([
            'status' => $status,
            'reason' => $transaction->reason_code,
            'transaction' => (new TransactionResource($transaction))->resolve($request),
        ], $httpStatus);
    }

    public function reverse(Request $request, int $id, ReversalService $reversals, WebhookDispatcher $webhooks): JsonResponse
    {
        $merchant = $this->merchant($request);

        // Merchant-scoped lookup: another merchant's transaction id is
        // deliberately indistinguishable from a nonexistent one.
        $transaction = Transaction::query()
            ->whereKey($id)
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($transaction === null) {
            return $this->error(404, 'transaction_not_found', sprintf('No transaction with id %d.', $id));
        }

        $data = $this->validateEnvelope($request, [
            'reason' => ['required', 'string', 'in:customer_refund,till_void,duplicate,other'],
            'occurred_at' => ['required', self::OCCURRED_AT_FORMATS],
        ]);

        try {
            $outcome = $reversals->reverse(
                $transaction,
                $this->actor($merchant),
                $data['reason'],
                CarbonImmutable::parse($data['occurred_at']),
            );
        } catch (FutureDatedTransactionException) {
            return $this->error(422, 'future_dated', 'occurred_at is in the future beyond the permitted clock-skew allowance.');
        } catch (InvalidReversalStateException $exception) {
            return $this->error(
                409,
                'invalid_state',
                $exception->getMessage(),
                meta: ['state' => $exception->transaction->state->value],
            );
        }

        // §9.3 transaction.reversed — emitted only when the transaction
        // actually transitioned to `reversed` here (vendors deduplicate by
        // transaction id, so echoing their own reversal is by design —
        // docs/openapi.yaml). An adjustment_created outcome leaves the
        // transaction untouched and emits nothing; a write-off is not a
        // reversal and never comes through this path. This controller is
        // deliberately the only §9.3 emit site for the event today: other
        // TransitionService::reverse callers are the immediate terminal
        // reversal of zero-accrual rows at creation (below-minimum /
        // suspended-merchant ingestion), which vendors observe synchronously
        // in the POST response — an async echo would be noise.
        if ($outcome->outcome === ReversalOutcome::REVERSED) {
            $webhooks->dispatch(WebhookEvents::TRANSACTION_REVERSED, [
                'transaction_id' => $outcome->transaction->id,
                'merchant_id' => $merchant->id,
                'invoice_no' => $outcome->transaction->invoice_no,
                'reason' => $data['reason'],
                'reversed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ]);
        }

        return new JsonResponse([
            'outcome' => $outcome->outcome,
            'cause' => $outcome->cause,
            'adjustment' => $outcome->adjustment !== null
                ? (new AdjustmentResource($outcome->adjustment))->resolve($request)
                : null,
            'transaction' => (new TransactionResource($outcome->transaction))->resolve($request),
        ], 200);
    }

    private function merchant(Request $request): Merchant
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return $merchant;
    }

    /**
     * The acting credential: the Sanctum personal access token id, recorded
     * as the pos actor on every event this request writes.
     */
    private function actor(Merchant $merchant): Actor
    {
        $token = $merchant->currentAccessToken();

        return Actor::pos($token instanceof PersonalAccessToken ? (int) $token->getKey() : null);
    }
}
