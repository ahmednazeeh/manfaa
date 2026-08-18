<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Adjustment\BackdatedIrreversibleException;
use App\Domain\Adjustment\InvalidReversalStateException;
use App\Domain\Adjustment\ReversalOutcome;
use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ApiCreditService;
use App\Domain\Cashback\CustomerNotFoundException;
use App\Domain\Cashback\CustomerRef;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\FutureDatedTransactionException;
use App\Domain\Cashback\LinePricingException;
use App\Domain\Cashback\LineSetParser;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Cashback\RateBelowAdvertisedException;
use App\Domain\Money\Laari;
use App\Domain\Money\Percent;
use App\Domain\Platform\RateNotPricedException;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Resources\V1\AdjustmentResource;
use App\Http\Resources\V1\TransactionResource;
use App\Http\Support\OccurredAt;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\Transaction;
use App\Rules\PercentRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * §9.2 vendor endpoints: record a sale, reverse a sale. This controller
 * never rejects a well-formed sale for business reasons — below-minimum
 * and suspended-merchant sales are recorded with zero cashback and a
 * distinct 200 body, so the cashier always sees something truthful.
 *
 * The one exception is an unusable `cashback_rate_percent` override on a
 * sale that would ACCRUE (PLAN §1): a rate the platform cannot price
 * (`rate_not_priced`) or one below the advertised rate
 * (`rate_below_advertised`) is refused 422 rather than silently ignored —
 * the till asked for terms we will not honour, and pretending otherwise
 * would put a wrong number on the customer's receipt. Omit the field and
 * the sale prices exactly as it always has. On a sale that grants nothing
 * either way — suspended merchant, below minimum — the field is ignored
 * instead (CreditRecorder), because those sales must still be recorded and
 * answered.
 */
class TransactionsController extends V1Controller
{
    public function store(Request $request, ApiCreditService $credits, LineSetParser $lineParser): JsonResponse
    {
        $data = $this->validateEnvelope($request, [
            'invoice_no' => ['required', 'string', 'max:64'],
            // Dual ref (CustomerRef): 6-digit code, or a Maldivian mobile
            // (+960XXXXXXX / 7-digit local starting 7 or 9). Phone-keyed
            // sales record origin api_phone; code-keyed stay pos.
            'customer_ref' => ['required', 'string', 'regex:'.CustomerRef::PATTERN],
            'eligible_amount' => ['required', 'integer', 'min:1'],
            'sale_amount' => ['nullable', 'integer', 'min:1'],
            // OPTIONAL (PLAN §1): omitted means NOW. ISO 8601 with an
            // offset, or a plain wall clock read as Maldives time
            // (App\Http\Support\OccurredAt).
            'occurred_at' => ['sometimes', 'nullable', OccurredAt::rule()],
            // Per-sale rate override (PLAN §1): a 2-decimal percent, string
            // or JSON number. Applies to THIS sale only; may only raise the
            // rate the sale would otherwise earn.
            'cashback_rate_percent' => ['sometimes', 'nullable', PercentRate::cashback()],
            'branch_id' => ['nullable', 'integer'],
            // Optional line-item split (docs/openapi.yaml): each line names
            // one of the merchant's product-category slugs
            // (GET /v1/merchants/me/product-categories), or null for the
            // default "everything else" bucket. Amounts must sum to
            // eligible_amount.
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.category' => ['present', 'nullable', 'string', 'max:80'],
            'lines.*.amount_laari' => ['required', 'integer', 'min:1'],
        ]);

        $merchant = $this->merchant($request);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($branchId !== null && ! MerchantBranch::query()->whereKey($branchId)->where('merchant_id', $merchant->id)->exists()) {
            return $this->error(422, 'validation_failed', 'The given data was invalid.', errors: [
                'branch_id' => ['The branch_id does not belong to this merchant.'],
            ]);
        }

        $overrideRateBp = isset($data['cashback_rate_percent'])
            ? Percent::toBasisPoints($data['cashback_rate_percent'])
            : null;

        $lines = null;

        if (isset($data['lines'])) {
            try {
                $lines = $lineParser->parse($merchant, $data['lines'], Laari::of((int) $data['eligible_amount']));
            } catch (LinePricingException $exception) {
                return $this->error(422, $exception->errorCode, $exception->getMessage());
            }
        }

        try {
            $transaction = $credits->credit(
                merchant: $merchant,
                actor: $this->actor($merchant),
                customerRef: $data['customer_ref'],
                invoiceNo: $data['invoice_no'],
                eligible: Laari::of((int) $data['eligible_amount']),
                saleAmount: isset($data['sale_amount']) ? Laari::of((int) $data['sale_amount']) : null,
                occurredAt: OccurredAt::fromRequest($data),
                branchId: $branchId,
                idempotencyKey: $request->header('Idempotency-Key'),
                lines: $lines,
                overrideRateBp: $overrideRateBp,
            );
        } catch (CustomerNotFoundException $exception) {
            return $this->error(422, 'customer_not_found', $exception->getMessage());
        } catch (FutureDatedTransactionException) {
            return $this->error(422, 'future_dated', 'occurred_at is in the future beyond the permitted clock-skew allowance.');
        } catch (NoEffectiveRateException) {
            return $this->error(422, 'no_effective_rate', 'No cashback rate is effective at occurred_at — contact the platform.');
        } catch (RateNotPricedException $exception) {
            // The override is structurally legal but the ACTIVE fee tier
            // schedule prices no fee for it — unsellable until the platform
            // publishes a wider table.
            return $this->error(422, RateNotPricedException::CODE, $exception->getMessage(), meta: [
                'ceiling_percent' => Percent::format($exception->ceilingBp()),
            ]);
        } catch (RateBelowAdvertisedException $exception) {
            // PLAN §1: the advertised rate is a public promise — an
            // override may only boost it.
            return $this->error(422, RateBelowAdvertisedException::CODE, $exception->getMessage(), meta: [
                'advertised_cashback_rate_percent' => Percent::format($exception->advertisedBp),
            ]);
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
            // Both mean the same thing to a till: recorded, priced at zero.
            // Why it was zero is in reason_code, which the response carries.
            'merchant_suspended', 'merchant_unpublished' => ['recorded_ineligible', 200],
            'below_minimum' => ['below_minimum', 200],
            default => ['created', 201],
        };

        // Only lined credits expose their pricing split; single-rate
        // responses keep the exact pre-lines shape.
        if ($lines !== null) {
            $transaction->load('lines');
        }

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
            // Same optional/flexible grammar as the create path.
            'occurred_at' => ['sometimes', 'nullable', OccurredAt::rule()],
        ]);

        try {
            $outcome = $reversals->reverse(
                $transaction,
                $this->actor($merchant),
                $data['reason'],
                OccurredAt::fromRequest($data),
            );
        } catch (FutureDatedTransactionException) {
            return $this->error(422, 'future_dated', 'occurred_at is in the future beyond the permitted clock-skew allowance.');
        } catch (BackdatedIrreversibleException $exception) {
            // PLAN §1: distinct from invalid_state on purpose — this one will
            // never succeed on a retry, in any state, so a vendor must route
            // it to a human rather than queue it.
            return $this->error(
                409,
                BackdatedIrreversibleException::ERROR_CODE,
                $exception->getMessage(),
                meta: ['state' => $exception->transaction->state->value],
            );
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
