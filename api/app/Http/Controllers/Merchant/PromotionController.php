<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Platform\RateNotPricedException;
use App\Domain\Promotions\BranchNotOwnedException;
use App\Domain\Promotions\InvalidPromotionWindowException;
use App\Domain\Promotions\NoStandingRateException;
use App\Domain\Promotions\PromotionNotDraftException;
use App\Domain\Promotions\PromotionRateNotBoostException;
use App\Domain\Promotions\PromotionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Http\Resources\RateResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant promotion builder (§10). Mutations need MANAGER or above — a
 * promotion reprices future sales and moves the platform fee tier, exactly
 * like a rate change, and both sit in the manager tier (PLAN §1). Staff may
 * read the list.
 *
 * The lifecycle endpoints only expose what the domain offers: create draft,
 * publish, cancel DRAFT. There is deliberately no update and no early-end
 * endpoint — PLAN §7: a published promotion is immutable for its stated
 * duration, and any attempt to publish/cancel a non-draft answers 409.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:draft,published,ended,cancelled'],
        ]);

        $query = Promotion::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return new JsonResponse(['data' => PromotionResource::collection($query->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);
        $user = $this->managerOrAbove($request);

        // §4: integer basis points 50–2000 (structural cap), or 4.995%
        // falls into no tier. The domain additionally refuses any rate the
        // ACTIVE fee tier schedule does not price (rate_not_priced below).
        // occurred_at-style timestamps must carry an explicit UTC offset.
        $validated = $request->validate([
            'rate_bp' => ['required', 'integer', 'min:50', 'max:2000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:sO'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:sO'],
            'min_purchase_laari' => ['nullable', 'integer', 'min:0'],
            'max_cashback_per_customer_laari' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        try {
            $promotion = $this->promotions->createDraft(
                merchant: $merchant,
                creator: $user,
                rateBp: (int) $validated['rate_bp'],
                startsAt: CarbonImmutable::parse($validated['starts_at']),
                endsAt: CarbonImmutable::parse($validated['ends_at']),
                minPurchaseLaari: isset($validated['min_purchase_laari']) ? (int) $validated['min_purchase_laari'] : null,
                maxCashbackPerCustomerLaari: isset($validated['max_cashback_per_customer_laari']) ? (int) $validated['max_cashback_per_customer_laari'] : null,
                branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
            );
        } catch (RateNotPricedException $e) {
            return $this->rateNotPriced($e);
        } catch (BranchNotOwnedException|InvalidPromotionWindowException|NoStandingRateException|PromotionRateNotBoostException $e) {
            abort(422, $e->getMessage());
        }

        return new JsonResponse([
            'data' => (new PromotionResource($promotion))->resolve(),
            'cost_preview' => $this->costPreview($merchant, $promotion),
        ], 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $this->managerOrAbove($request);
        $promotion = $this->find($request, $id);

        try {
            $promotion = $this->promotions->publish($promotion);
        } catch (PromotionNotDraftException $e) {
            abort(409, $e->getMessage());
        } catch (RateNotPricedException $e) {
            return $this->rateNotPriced($e);
        } catch (InvalidPromotionWindowException|NoStandingRateException|PromotionRateNotBoostException $e) {
            abort(422, $e->getMessage());
        }

        // A promotion that is already inside its window becomes the rate the
        // storefront quotes the moment it publishes; drop the 60s read model
        // so the card and store page do not keep advertising the standing
        // rate (DiscoveryService::forgetMerchant). A future-dated window is
        // dropped too — the entry simply rebuilds identically.
        DiscoveryService::forgetMerchant($this->merchant($request));

        return new JsonResponse([
            'data' => (new PromotionResource($promotion))->resolve(),
            'cost_preview' => $this->costPreview($this->merchant($request), $promotion),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->managerOrAbove($request);
        $promotion = $this->find($request, $id);

        try {
            $promotion = $this->promotions->cancelDraft($promotion);
        } catch (PromotionNotDraftException $e) {
            // A published promotion can never be cancelled — that would be
            // the forbidden early end (PLAN §7).
            abort(409, $e->getMessage());
        }

        return new JsonResponse(['data' => (new PromotionResource($promotion))->resolve()]);
    }

    /**
     * The all-in cost picture (§4): what the merchant pays per transaction
     * during the promotion versus their standing terms at the window start,
     * with the tier-cliff data the UI must warn about (499 → 500: +0.01pp
     * cashback costs +0.26pp all-in).
     *
     * @return array<string, mixed>
     */
    private function costPreview(Merchant $merchant, Promotion $promotion): array
    {
        $standingBp = $this->promotions->standingRateBpAt($merchant, $promotion->starts_at);

        // Fees priced under the admin-managed schedule in force when the
        // window runs (its start if still future, now once started) — the
        // same source credit-time billing resolves from.
        $now = CarbonImmutable::now('UTC');
        $feeInstant = $promotion->starts_at->utc()->isAfter($now) ? $promotion->starts_at->utc() : $now;

        $promo = RateResource::describeBp($promotion->rate_bp, $feeInstant);
        $standing = $standingBp === null ? null : RateResource::describeBp($standingBp, $feeInstant);

        return [
            'promo' => $promo,
            'standing' => $standing,
            'all_in_delta_bp' => $standing === null ? null : $promo['all_in_bp'] - $standing['all_in_bp'],
            'tier_changed' => $standing !== null && $promo['fee_bp'] !== $standing['fee_bp'],
        ];
    }

    /**
     * 422 with machine-readable `code: rate_not_priced` — the promo rate is
     * structurally legal but the active fee tier schedule does not price it
     * (unsellable until the admin publishes a wider table).
     */
    private function rateNotPriced(RateNotPricedException $e): JsonResponse
    {
        return new JsonResponse([
            'message' => $e->getMessage(),
            'code' => RateNotPricedException::CODE,
        ], 422);
    }

    private function find(Request $request, int $id): Promotion
    {
        $promotion = Promotion::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->find($id);

        if ($promotion === null) {
            abort(404, 'Promotion not found.');
        }

        return $promotion;
    }

    /**
     * Manager or owner (PLAN §1) — mirrors the standing rate change: staff
     * can read promotions, never reprice the shop. Belt-and-braces behind
     * the merchant.role:manager route gate.
     */
    private function managerOrAbove(Request $request): MerchantUser
    {
        $user = $request->user();

        if (! $user instanceof MerchantUser || ! $user->hasRoleAtLeast('manager')) {
            abort(403, 'Only a merchant owner or manager can manage promotions.');
        }

        return $user;
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user();

        return $user->merchant;
    }
}
