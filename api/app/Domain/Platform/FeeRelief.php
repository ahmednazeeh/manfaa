<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * The promotional platform fee in force for ONE merchant at ONE instant, in
 * the only shape pricing needs: a kind and a fee in basis points.
 *
 * ## The one rule
 *
 *     charged fee = min(the promotion's fee, the §4 tier's fee)
 *
 * A promotion may only ever make a sale CHEAPER. A merchant whose own tier
 * already charges less than the offer keeps their tier — `reduces()` answers
 * false, nothing is stamped, and the row is indistinguishable from one
 * priced with no promotion running at all, which is exactly what it is.
 * That is belt and braces: FeePromotionsController refuses to STORE a fee
 * above the cheapest tier it could replace (a "promotion" that costs more is
 * a mistake, not a promotion), and this min() is the braces — a schedule
 * published later can introduce a cheaper tier than the one the promotion
 * was checked against, and no merchant may ever pay more because of it.
 *
 * ## Zero
 *
 * `feeBp = 0` is a first-class value: the free-for-X-days case the owner
 * asked for. It is the reason CashbackCalculator takes its fee override
 * through `Rate::chargedFee()` (0–2000 bp) rather than `Rate::fee()`
 * (1–2000): a tier fee may never be zero, a CHARGED fee now may.
 *
 * ## Frozen vs live
 *
 * Two sources, and the distinction is the whole frozen-terms guarantee:
 *
 *  - `FeePromotionPolicy::reliefFor()` resolves the LIVE promotion for a new
 *    sale;
 *  - `fromStamp()` rebuilds the relief a row was already priced under, from
 *    its own `fee_promo_kind` / `fee_promo_fee_bp` columns. AmendmentService
 *    uses it so that correcting a sale after a promotion ended reproduces
 *    the terms the sale was rung up under instead of today's.
 */
final readonly class FeeRelief
{
    private function __construct(
        public ?FeePromotionKind $kind,
        public ?int $feeBp,
    ) {}

    /** No promotion — the tier's own fee stands. */
    public static function none(): self
    {
        return new self(null, null);
    }

    public static function of(FeePromotionKind $kind, int $feeBp): self
    {
        // A negative fee is impossible through the table's CHECK; it is
        // floored here anyway, because a fee of "minus one percent" must
        // never become money.
        return new self($kind, max(0, $feeBp));
    }

    /**
     * The relief a row was priced under, rebuilt from its own stamp. An
     * unrecognised or absent kind answers none(), which is the identity —
     * re-pricing a row written before this feature existed reproduces it
     * byte for byte.
     */
    public static function fromStamp(?string $kind, ?int $feeBp): self
    {
        $case = $kind === null ? null : FeePromotionKind::tryFrom($kind);

        if ($case === null || $feeBp === null) {
            return self::none();
        }

        return self::of($case, $feeBp);
    }

    /** Is there a promotion at all? */
    public function applies(): bool
    {
        return $this->kind !== null && $this->feeBp !== null;
    }

    /**
     * Does this promotion actually make THIS tier fee cheaper? The question
     * every stamping decision asks: a promotion that beats nothing on a sale
     * must not stamp that sale, or a report would claim an acquisition cost
     * of zero laari on a row nobody discounted.
     */
    public function reduces(int $tierFeeBp): bool
    {
        return $this->applies() && $this->feeBp < $tierFeeBp;
    }

    /** The fee actually charged: the lower of the offer and the tier. */
    public function chargedFeeBp(int $tierFeeBp): int
    {
        return $this->reduces($tierFeeBp) ? (int) $this->feeBp : $tierFeeBp;
    }
}
