<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Cashback\CustomerNotFoundException;
use App\Domain\Cashback\DuplicateInvoiceException;
use App\Domain\Cashback\FutureDatedTransactionException;
use App\Domain\Cashback\LinePricingException;
use App\Domain\Cashback\LineSetParser;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\MerchantNotActiveException;
use App\Domain\Cashback\NoEffectiveRateException;
use App\Domain\Cashback\RateBelowAdvertisedException;
use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Laari;
use App\Domain\Money\Percent;
use App\Domain\Platform\RateNotPricedException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Resources\TransactionResource;
use App\Http\Support\OccurredAt;
use App\Models\MerchantUser;
use App\Rules\PercentRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function __construct(
        private readonly ManualCreditService $credits,
        private readonly LineSetParser $lineParser,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_code' => ['required', 'string', 'digits:6'],
            'invoice_no' => ['required', 'string', 'max:64'],
            'eligible_amount' => ['required', 'integer', 'min:0'],
            'sale_amount' => ['nullable', 'integer', 'gte:eligible_amount'],
            // OPTIONAL (PLAN §1): omitted means NOW. ISO 8601 with an
            // offset, or a plain wall clock read as Maldives time
            // (App\Http\Support\OccurredAt).
            'occurred_at' => ['sometimes', 'nullable', OccurredAt::rule()],
            // Per-sale rate override (PLAN §1): a 2-decimal percent for
            // THIS sale only, never below the rate it would otherwise earn.
            'cashback_rate_percent' => ['sometimes', 'nullable', PercentRate::cashback()],
            // Optional line-item split (Task #25): each line names one of
            // the merchant's product-category slugs, or null for the
            // default "everything else" bucket. Line amounts must sum to
            // eligible_amount.
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.category' => ['present', 'nullable', 'string', 'max:80'],
            'lines.*.amount_laari' => ['required', 'integer', 'min:1'],
        ]);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $eligible = Laari::of((int) $validated['eligible_amount']);

        // The ONLY gate on the override. The route needs `credits.create`,
        // which every till holds; a per-sale rate is a pricing decision on
        // top of it, so it gets its own permission and is checked here
        // because it gates a FIELD, not the endpoint. Without it an account
        // trusted to key sales in would be a bigger per-sale spending lever
        // than the standing rate it is deliberately denied: one sale at the
        // schedule ceiling costs the merchant several times the standing
        // terms, and nothing else bounds it.
        if (isset($validated['cashback_rate_percent']) && ! $user->can(Permission::CreditsCustomRate)) {
            return EnsureMerchantPermission::deny(Permission::CreditsCustomRate);
        }

        $overrideRateBp = isset($validated['cashback_rate_percent'])
            ? Percent::toBasisPoints($validated['cashback_rate_percent'])
            : null;

        $lines = null;

        if (isset($validated['lines'])) {
            try {
                $lines = $this->lineParser->parse($user->merchant, $validated['lines'], $eligible);
            } catch (LinePricingException $e) {
                return new JsonResponse([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], 422);
            }
        }

        try {
            $transaction = $this->credits->credit(
                merchant: $user->merchant,
                actor: $user,
                customerCode: $validated['customer_code'],
                invoiceNo: $validated['invoice_no'],
                eligible: $eligible,
                saleAmount: isset($validated['sale_amount']) ? Laari::of((int) $validated['sale_amount']) : null,
                occurredAt: OccurredAt::fromRequest($validated),
                lines: $lines,
                overrideRateBp: $overrideRateBp,
            );
        } catch (CustomerNotFoundException $e) {
            abort(404, $e->getMessage());
        } catch (DuplicateInvoiceException $e) {
            abort(409, $e->getMessage());
        } catch (MerchantNotActiveException|FutureDatedTransactionException|NoEffectiveRateException $e) {
            abort(422, $e->getMessage());
        } catch (RateNotPricedException $e) {
            // A rate override the ACTIVE fee tier schedule cannot price.
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422);
        } catch (RateBelowAdvertisedException $e) {
            // PLAN §1: an override may only raise the advertised rate.
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateBelowAdvertisedException::CODE,
                'advertised_cashback_rate_percent' => Percent::format($e->advertisedBp),
            ], 422);
        }

        // Only lined credits expose their pricing split; single-rate
        // responses keep the exact pre-lines shape.
        if ($lines !== null) {
            $transaction->load('lines');
        }

        return (new TransactionResource($transaction))
            ->response($request)
            ->setStatusCode(201);
    }
}
