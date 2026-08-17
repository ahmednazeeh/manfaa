<?php

declare(strict_types=1);

namespace App\Http\Support;

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
use App\Http\Middleware\EnsureMerchantPermission;
use App\Models\MerchantUser;
use App\Models\Transaction;
use App\Rules\PercentRate;
use Illuminate\Http\JsonResponse;

/**
 * The parts of "record a manual credit" that BOTH the panel and the till app
 * must agree on.
 *
 * Extracted when the mobile endpoint arrived (M5). The rules and the
 * exception mapping are the drift-prone halves: a validation rule tightened
 * in one place and not the other, or a new domain exception caught in one
 * controller and not the other, would show up as a 500 at a till rather than
 * as the refusal the merchant expects. Sharing them makes that impossible
 * rather than merely unlikely.
 *
 * The two controllers still differ where they SHOULD — the mobile one
 * demands an idempotency key and an explicit acknowledgement of a backdated
 * sale; the panel has a human ticking a box instead.
 */
trait HandlesCreditRequests
{
    /**
     * The wire contract for a credit, shared by both surfaces.
     *
     * @return array<string, mixed>
     */
    protected function creditRules(): array
    {
        return [
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
        ];
    }

    /**
     * The per-sale override, once the caller has proved it may set one.
     *
     * The route needs `credits.create`, which every till holds; a per-sale
     * rate is a pricing decision on top of it, so it gets its own permission
     * and is checked HERE because it gates a FIELD, not the endpoint.
     * Without it an account trusted to key sales in would be a bigger
     * per-sale spending lever than the standing rate it is deliberately
     * denied.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: ?int, 1: ?JsonResponse} the rate, or the refusal
     */
    protected function overrideRateBp(array $validated, MerchantUser $user): array
    {
        if (! isset($validated['cashback_rate_percent'])) {
            return [null, null];
        }

        if (! $user->can(Permission::CreditsCustomRate)) {
            return [null, EnsureMerchantPermission::deny(Permission::CreditsCustomRate)];
        }

        return [Percent::toBasisPoints($validated['cashback_rate_percent']), null];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: mixed, 1: ?JsonResponse}
     */
    protected function creditLines(array $validated, MerchantUser $user, Laari $eligible, LineSetParser $parser): array
    {
        if (! isset($validated['lines'])) {
            return [null, null];
        }

        try {
            return [$parser->parse($user->merchant, $validated['lines'], $eligible), null];
        } catch (LinePricingException $e) {
            return [null, new JsonResponse([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 422)];
        }
    }

    /**
     * Record the sale, mapping every domain refusal onto its HTTP answer.
     *
     * @return array{0: ?Transaction, 1: ?JsonResponse}
     */
    protected function recordCredit(ManualCreditService $credits, callable $call): array
    {
        try {
            return [$call($credits), null];
        } catch (CustomerNotFoundException $e) {
            // A CODE, not a bare abort(). abort(404) renders through
            // MobileError as the generic "That could not be found." — which
            // is correct for a wrong URL and useless at a till, where the
            // cashier needs to know they mistyped the customer's number.
            // The panel is unaffected: it reads `message` either way.
            return [null, self::refusal($e->getMessage(), 'customer_not_found', 404)];
        } catch (DuplicateInvoiceException $e) {
            return [null, self::refusal($e->getMessage(), 'duplicate_invoice', 409)];
        } catch (MerchantNotActiveException $e) {
            return [null, self::refusal($e->getMessage(), 'merchant_not_active', 422)];
        } catch (FutureDatedTransactionException $e) {
            // Published on /v1 under this same name.
            return [null, self::refusal($e->getMessage(), 'future_dated', 422)];
        } catch (NoEffectiveRateException $e) {
            return [null, self::refusal($e->getMessage(), 'no_effective_rate', 422)];
        } catch (RateNotPricedException $e) {
            // A rate override the ACTIVE fee tier schedule cannot price.
            return [null, new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422)];
        } catch (RateBelowAdvertisedException $e) {
            // PLAN §1: an override may only raise the advertised rate.
            return [null, new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateBelowAdvertisedException::CODE,
                'advertised_cashback_rate_percent' => Percent::format($e->advertisedBp),
            ], 422)];
        }
    }

    /**
     * A refusal carrying its own code.
     *
     * These used to be `abort($status, $message)`, which throws a bare
     * HttpException — and on the mobile tree MobileError then maps it by
     * STATUS, so three terminal refusals (suspended store, future-dated,
     * no rate) all arrived as `validation_failed` with no meta, telling the
     * till to show them against a form field that cannot fix them. Their
     * siblings below always returned codes; now all of them do.
     */
    private static function refusal(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], $status);
    }
}
